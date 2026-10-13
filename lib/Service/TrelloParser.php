<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\ImportWarning;
use OCA\DeckTrello\Model\ParsedAttachment;
use OCA\DeckTrello\Model\ParsedBoard;
use OCA\DeckTrello\Model\ParsedChecklist;
use OCA\DeckTrello\Model\ParsedChecklistItem;
use OCA\DeckTrello\Model\ParsedCard;
use OCA\DeckTrello\Model\ParsedComment;
use OCA\DeckTrello\Model\ParsedLabel;
use OCA\DeckTrello\Model\ParsedList;
use OCA\DeckTrello\Model\ParsedMember;

class TrelloParser {
	public const BOARD_TITLE_MAX = 100;
	public const STACK_TITLE_MAX = 100;
	public const CARD_TITLE_MAX = 255;
	public const LABEL_TITLE_MAX = 100;
	public const ACTION_LIMIT = 1000;

	private const LABEL_COLORS = [
		'green' => '31CC7C',
		'lime' => '31CC7C',
		'blue' => '317CCC',
		'sky' => '317CCC',
		'red' => 'FF7A66',
		'orange' => 'FF7A66',
		'yellow' => 'F1DB50',
		'purple' => '7C31CC',
		'pink' => 'CC317C',
		'black' => '3A3B3D',
	];

	private const DEFAULT_COLOR = '317CCC';

	public function parse(string $json, bool $includeClosedLists = false, bool $completeHistory = false): ParsedBoard {
		$json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \InvalidArgumentException('The file is not valid JSON.', 0, $e);
		}
		if (!is_array($data) || array_is_list($data)) {
			throw new \InvalidArgumentException('The file is not a Trello board export.');
		}

		$warnings = [];
		$rawLists = $this->listOfArrays($data['lists'] ?? []);
		usort($rawLists, static fn (array $a, array $b): int => ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0));

		$closedListIds = [];
		$importedLists = [];
		$order = 0;
		foreach ($rawLists as $list) {
			$id = $this->stringId($list['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$closed = (bool)($list['closed'] ?? false);
			if ($closed) {
				$closedListIds[$id] = true;
			}
			if ($closed && !$includeClosedLists) {
				continue;
			}
			$title = $this->limit($this->stringValue($list['name'] ?? ''), self::STACK_TITLE_MAX);
			if ($title === '') {
				$title = 'Untitled list';
			}
			$importedLists[] = new ParsedList($id, $title, $order, $closed);
			$order++;
		}
		$importedListIds = [];
		foreach ($importedLists as $list) {
			$importedListIds[$list->id] = true;
		}

		$labelTitles = [];
		$labels = [];
		foreach ($this->listOfArrays($data['labels'] ?? []) as $label) {
			$id = $this->stringId($label['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$trelloColor = strtolower($this->stringValue($label['color'] ?? ''));
			$name = $this->stringValue($label['name'] ?? '');
			$fallback = $trelloColor !== '' ? $trelloColor : 'label';
			$title = $this->allocateTitle($name, self::LABEL_TITLE_MAX, $labelTitles, $fallback);
			$labels[] = new ParsedLabel($id, $title, $this->mapColor($trelloColor));
		}

		$membersById = [];
		foreach ($this->listOfArrays($data['members'] ?? []) as $member) {
			$id = $this->stringId($member['id'] ?? '');
			$username = $this->stringValue($member['username'] ?? '');
			if ($id === '' || $username === '') {
				continue;
			}
			$membersById[$id] = new ParsedMember($username, $this->stringValue($member['fullName'] ?? ''));
		}

		$checklistsByCard = [];
		foreach ($this->listOfArrays($data['checklists'] ?? []) as $checklist) {
			$cardId = $this->stringId($checklist['idCard'] ?? '');
			if ($cardId === '') {
				continue;
			}
			$items = $this->listOfArrays($checklist['checkItems'] ?? []);
			usort($items, static fn (array $a, array $b): int => ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0));
			$checklistsByCard[$cardId][] = [
				'name' => $this->stringValue($checklist['name'] ?? '') ?: 'Checklist',
				'pos' => $checklist['pos'] ?? 0,
				'items' => $items,
			];
		}
		foreach ($checklistsByCard as &$cardChecklists) {
			usort($cardChecklists, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
		}
		unset($cardChecklists);

		$commentsByCard = [];
		$actions = $this->listOfArrays($data['actions'] ?? []);
		$actionsTruncated = !$completeHistory && count($actions) >= self::ACTION_LIMIT;
		if ($actionsTruncated) {
			$warnings[] = new ImportWarning('actions_truncated');
		}
		foreach ($actions as $action) {
			if (($action['type'] ?? '') !== 'commentCard') {
				continue;
			}
			$actionData = is_array($action['data'] ?? null) ? $action['data'] : [];
			$card = is_array($actionData['card'] ?? null) ? $actionData['card'] : [];
			$cardId = $this->stringId($card['id'] ?? '');
			$message = $this->stringValue($actionData['text'] ?? '');
			if ($cardId === '' || $message === '') {
				continue;
			}
			$creator = is_array($action['memberCreator'] ?? null) ? $action['memberCreator'] : [];
			$author = $this->stringValue($creator['fullName'] ?? '');
			if ($author === '') {
				$author = $this->stringValue($creator['username'] ?? '');
			}
			if ($author === '') {
				$author = 'Trello';
			}
			$commentsByCard[$cardId][] = new ParsedComment(
				$author,
				$this->stringValue($action['date'] ?? ''),
				$message,
			);
		}
		foreach ($commentsByCard as &$cardComments) {
			usort($cardComments, static fn (ParsedComment $a, ParsedComment $b): int => strcmp($a->date, $b->date));
		}
		unset($cardComments);

		$rawCards = $this->listOfArrays($data['cards'] ?? []);
		usort($rawCards, static fn (array $a, array $b): int => ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0));

		$cards = [];
		$orderByList = [];
		$archivedCards = 0;
		$checklistCount = 0;
		$commentCount = 0;
		$attachmentCount = 0;
		$memberNames = [];
		$skippedClosed = 0;
		$skippedMissing = 0;
		$truncatedTitles = 0;

		foreach ($rawCards as $card) {
			$listId = $this->stringId($card['idList'] ?? '');
			if (!isset($importedListIds[$listId])) {
				if (isset($closedListIds[$listId])) {
					$skippedClosed++;
				} else {
					$skippedMissing++;
				}
				continue;
			}

			$cardId = $this->stringId($card['id'] ?? '');
			$originalTitle = trim($this->stringValue($card['name'] ?? ''));
			if (mb_strlen($originalTitle) > self::CARD_TITLE_MAX) {
				$truncatedTitles++;
			}
			$title = $this->limit($originalTitle, self::CARD_TITLE_MAX);
			if ($title === '') {
				$title = 'Untitled';
			}
			$rawChecklists = $checklistsByCard[$cardId] ?? [];
			$checklists = $this->parseChecklists($rawChecklists);
			$checklistMarkdown = $this->checklistMarkdown($rawChecklists);
			$attachments = $this->parseAttachments($this->listOfArrays($card['attachments'] ?? []));
			$attachmentMarkdown = $this->attachmentMarkdown($attachments);
			if ($attachmentMarkdown !== '') {
				$attachmentCount += substr_count($attachmentMarkdown, "\n- [");
			}
			$comments = $commentsByCard[$cardId] ?? [];
			$members = [];
			foreach ($card['idMembers'] ?? [] as $memberId) {
				$memberId = $this->stringId($memberId);
				if (isset($membersById[$memberId])) {
					$members[] = $membersById[$memberId];
					$memberNames[$membersById[$memberId]->username] = true;
				}
			}

			$due = $this->dueTimestamp($card['due'] ?? null);
			$archived = (bool)($card['closed'] ?? false);
			if ($archived) {
				$archivedCards++;
			}
			$checklistCount += count($checklists);
			$commentCount += count($comments);
			$orderIndex = $orderByList[$listId] ?? 0;
			$orderByList[$listId] = $orderIndex + 1;

			$labelIds = [];
			foreach ($card['idLabels'] ?? [] as $labelId) {
				$labelId = $this->stringId($labelId);
				if ($labelId !== '') {
					$labelIds[] = $labelId;
				}
			}

			$cards[] = new ParsedCard(
				$title,
				$this->stringValue($card['desc'] ?? ''),
				$checklistMarkdown,
				$attachmentMarkdown,
				$due,
				$orderIndex,
				$archived,
				$listId,
				$labelIds,
				$members,
				$comments,
				$attachments,
				$checklists,
			);
		}

		if ($skippedClosed > 0) {
			$warnings[] = new ImportWarning('skipped_closed', ['count' => $skippedClosed]);
		}
		if ($skippedMissing > 0) {
			$warnings[] = new ImportWarning('skipped_missing_list', ['count' => $skippedMissing]);
		}
		if ($truncatedTitles > 0) {
			$warnings[] = new ImportWarning('titles_truncated', ['count' => $truncatedTitles]);
		}

		$boardTitleSource = trim($this->stringValue($data['name'] ?? ''));
		if (mb_strlen($boardTitleSource) > self::BOARD_TITLE_MAX) {
			$warnings[] = new ImportWarning('board_title_truncated');
		}
		$boardTitle = $this->limit($boardTitleSource, self::BOARD_TITLE_MAX);
		if ($boardTitle === '') {
			$boardTitle = 'Imported Trello board';
		}
		$description = $this->stringValue($data['desc'] ?? '');
		if ($description !== '') {
			$warnings[] = new ImportWarning('board_description');
		}

		$memberUsernames = $this->sortedKeys($memberNames);
		$profiles = [];
		foreach ($membersById as $member) {
			if (!isset($memberNames[$member->username])) {
				continue;
			}
			$profiles[] = [
				'username' => $member->username,
				'fullName' => $member->fullName,
			];
		}
		usort($profiles, static fn (array $a, array $b): int => strcasecmp($a['username'], $b['username']));

		return new ParsedBoard(
			$boardTitle,
			$description,
			$importedLists,
			$labels,
			$cards,
			$archivedCards,
			$checklistCount,
			$commentCount,
			$attachmentCount,
			$memberUsernames,
			$skippedClosed,
			$skippedMissing,
			$actionsTruncated,
			$warnings,
			$profiles,
		);
	}

	/**
	 * @param array<string, true> $used
	 */
	private function allocateTitle(string $raw, int $max, array &$used, string $fallback): string {
		$base = trim($raw);
		if ($base === '') {
			$base = $fallback;
		}
		$base = $this->limit($base, $max);
		$title = $base;
		$n = 2;
		while (isset($used[mb_strtolower($title)])) {
			$suffix = ' (' . $n . ')';
			$title = $this->limit($base, $max - mb_strlen($suffix)) . $suffix;
			$n++;
		}
		$used[mb_strtolower($title)] = true;
		return $title;
	}

	private function limit(string $value, int $max): string {
		if (mb_strlen($value) <= $max) {
			return $value;
		}
		if ($max <= 1) {
			return mb_substr($value, 0, $max);
		}
		return mb_substr($value, 0, $max - 1) . '…';
	}

	private function mapColor(string $trelloColor): string {
		return self::LABEL_COLORS[$trelloColor] ?? self::DEFAULT_COLOR;
	}

	/**
	 * @param list<array{name: string, items: list<array<string, mixed>>}> $checklists
	 * @return list<ParsedChecklist>
	 */
	private function parseChecklists(array $checklists): array {
		$parsed = [];
		foreach ($checklists as $checklist) {
			$items = [];
			foreach ($checklist['items'] as $item) {
				$title = trim(str_replace(["\r", "\n"], ' ', $this->stringValue($item['name'] ?? '')));
				if ($title === '') {
					continue;
				}
				$items[] = new ParsedChecklistItem($title, ($item['state'] ?? '') === 'complete');
			}
			$title = trim(str_replace(["\r", "\n"], ' ', $this->stringValue($checklist['name'] ?? '')));
			if ($title === '') {
				$title = 'Checklist';
			}
			$parsed[] = new ParsedChecklist($title, $items);
		}
		return $parsed;
	}

	/**
	 * @param list<array{name: string, items: list<array<string, mixed>>}> $checklists
	 */
	private function checklistMarkdown(array $checklists): string {
		$blocks = [];
		foreach ($checklists as $checklist) {
			$lines = ['## ' . str_replace(["\r", "\n"], ' ', $checklist['name'])];
			foreach ($checklist['items'] as $item) {
				$name = str_replace(["\r", "\n"], ' ', $this->stringValue($item['name'] ?? ''));
				$box = ($item['state'] ?? '') === 'complete' ? 'x' : ' ';
				$lines[] = '- [' . $box . '] ' . $name;
			}
			$blocks[] = implode("\n", $lines);
		}
		return implode("\n\n", $blocks);
	}

	/**
	 * @param list<array<string, mixed>> $attachments
	 * @return list<ParsedAttachment>
	 */
	private function parseAttachments(array $attachments): array {
		$parsed = [];
		foreach ($attachments as $attachment) {
			$url = $this->stringValue($attachment['url'] ?? '');
			if (!preg_match('#^https?://#i', $url)) {
				continue;
			}
			$name = $this->stringValue($attachment['name'] ?? '');
			if ($name === '') {
				$name = $this->stringValue($attachment['fileName'] ?? '');
			}
			$bytes = $attachment['bytes'] ?? 0;
			$parsed[] = new ParsedAttachment(
				$this->stringId($attachment['id'] ?? ''),
				$name,
				$url,
				$this->stringValue($attachment['mimeType'] ?? ''),
				is_numeric($bytes) ? (int)$bytes : 0,
				(bool)($attachment['isUpload'] ?? false),
			);
		}
		return $parsed;
	}

	/**
	 * @param list<ParsedAttachment> $attachments
	 */
	private function attachmentMarkdown(array $attachments): string {
		$lines = [];
		foreach ($attachments as $attachment) {
			$name = $attachment->name !== '' ? $attachment->name : $attachment->url;
			$name = str_replace(['[', ']'], ['(', ')'], str_replace(["\r", "\n"], ' ', $name));
			$lines[] = '- [' . $name . '](' . $attachment->url . ')';
		}
		if ($lines === []) {
			return '';
		}
		return "## Pièces jointes\n" . implode("\n", $lines);
	}

	private function dueTimestamp(mixed $due): ?int {
		if (!is_string($due) || trim($due) === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($due))->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function listOfArrays(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$items = [];
		foreach ($value as $item) {
			if (is_array($item)) {
				$items[] = $item;
			}
		}
		return $items;
	}

	private function stringValue(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		return '';
	}

	/**
	 * @param array<string, true> $values
	 * @return list<string>
	 */
	private function sortedKeys(array $values): array {
		$keys = array_keys($values);
		sort($keys, SORT_STRING);
		return $keys;
	}

	private function stringId(mixed $value): string {
		return trim($this->stringValue($value));
	}
}
