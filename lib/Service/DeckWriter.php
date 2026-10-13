<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\ImportResult;
use OCA\DeckTrello\Model\ImportWarning;
use OCA\DeckTrello\Model\ParsedAttachment;
use OCA\DeckTrello\Model\ParsedBoard;
use OCA\DeckTrello\Model\ParsedCard;
use OCA\DeckTrello\Model\ParsedComment;
use OCA\DeckTrello\Model\ParsedMember;
use OCA\DeckTrello\Model\TrelloCredentials;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;

class DeckWriter {
	private const BOARD_COLOR = '317CCC';
	private const COMMENT_MAX = 1000;
	private const DOWNLOAD_TOTAL_MAX = 209715200;

	public function __construct(
		private readonly ICommentsManager $commentsManager,
		private readonly IUserManager $userManager,
		private readonly TrelloAttachmentClient $attachmentClient,
	) {
	}

	/**
	 * @param array<string, string> $userMap lowercase Trello username => Nextcloud uid
	 */
	public function import(ParsedBoard $parsed, string $userId, array $userMap = [], ?TrelloCredentials $credentials = null, bool $useDeckChecklists = false): ImportResult {
		if (!class_exists(\OCA\Deck\Service\BoardService::class)) {
			throw new \RuntimeException('The Deck app must be installed and enabled.');
		}

		$permissionService = \OCP\Server::get(\OCA\Deck\Service\PermissionService::class);
		$permissionService->setUserId($userId);
		$boardService = \OCP\Server::get(\OCA\Deck\Service\BoardService::class);
		$stackService = \OCP\Server::get(\OCA\Deck\Service\StackService::class);
		$cardService = \OCP\Server::get(\OCA\Deck\Service\CardService::class);
		$labelService = \OCP\Server::get(\OCA\Deck\Service\LabelService::class);
		$assignmentService = \OCP\Server::get(\OCA\Deck\Service\AssignmentService::class);

		$warnings = $parsed->warnings;
		$deckChecklists = $this->deckChecklistEnabled($useDeckChecklists, $warnings);
		$board = $boardService->create($parsed->title, $userId, self::BOARD_COLOR);
		$boardId = (int)$board->getId();
		$this->removeDefaultLabels($boardService, $labelService, $boardId);

		$stackIds = [];
		$stacksCreated = 0;
		foreach ($parsed->lists as $list) {
			try {
				$stack = $stackService->create($list->title, $boardId, $list->order);
				$stackIds[$list->id] = (int)$stack->getId();
				$stacksCreated++;
			} catch (\Throwable $e) {
				$warnings[] = new ImportWarning('stack_failed', [
					'title' => $list->title,
					'message' => $e->getMessage(),
				]);
			}
		}

		$labelIds = [];
		$labelsCreated = 0;
		foreach ($parsed->labels as $label) {
			try {
				$created = $labelService->create($label->title, $label->color, $boardId);
				$labelIds[$label->id] = (int)$created->getId();
				$labelsCreated++;
			} catch (\Throwable $e) {
				$existingId = $this->findLabelId($boardService, $boardId, $label->title);
				if ($existingId === null) {
					$warnings[] = new ImportWarning('label_failed', [
						'title' => $label->title,
						'message' => $e->getMessage(),
					]);
					continue;
				}
				try {
					$labelService->update($existingId, $label->title, $label->color);
				} catch (\Throwable) {
					// Keep the existing label when its color cannot be changed.
				}
				$labelIds[$label->id] = $existingId;
				$labelsCreated++;
			}
		}

		$sharedUsers = [];
		$resolvedCache = [];
		foreach ($parsed->cards as $card) {
			foreach ($card->members as $member) {
				$resolved = $this->resolveUser($member, $userMap, $resolvedCache, $warnings);
				if ($resolved !== null && $resolved !== $userId && !isset($sharedUsers[$resolved])) {
					try {
						$boardService->addAcl($boardId, \OCA\Deck\Db\Acl::PERMISSION_TYPE_USER, $resolved, true, false, false);
						$sharedUsers[$resolved] = true;
					} catch (\Throwable $e) {
						$warnings[] = new ImportWarning('share_failed', [
							'user' => $resolved,
							'message' => $e->getMessage(),
						]);
						$sharedUsers[$resolved] = false;
					}
				}
			}
		}

		$cardsCreated = 0;
		$archivedCards = 0;
		$commentsCreated = 0;
		$assignedUsers = 0;
		$attachmentsImported = 0;
		$downloadedBytes = 0;
		$descriptionStored = false;
		if ($credentials === null) {
			$downloadable = $this->countDownloadable($parsed);
			if ($downloadable > 0) {
				$warnings[] = new ImportWarning('attachments_need_token', ['count' => $downloadable]);
			}
		}
		$firstStackId = $stackIds === [] ? null : reset($stackIds);
		if (trim($parsed->description) !== '') {
			if ($firstStackId === null) {
				$warnings[] = new ImportWarning('no_list_for_description');
			} else {
				try {
					$cardService->create('Description', $firstStackId, 'plain', 0, $userId, $parsed->description);
					$cardsCreated++;
					$descriptionStored = true;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('card_failed', [
						'title' => 'Description',
						'message' => $e->getMessage(),
					]);
				}
			}
		}

		foreach ($parsed->cards as $card) {
			$stackId = $stackIds[$card->listId] ?? null;
			if ($stackId === null) {
				$warnings[] = new ImportWarning('card_failed', [
					'title' => $card->title,
					'message' => 'The list was not created.',
				]);
				continue;
			}
			$unmapped = [];
			$assignees = [];
			foreach ($card->members as $member) {
				$resolved = $this->resolveUser($member, $userMap, $resolvedCache, $warnings);
				if ($resolved === null || (isset($sharedUsers[$resolved]) && $sharedUsers[$resolved] === false)) {
					$unmapped[] = $member->display();
					continue;
				}
				$assignees[$resolved] = $member;
			}
			$order = $descriptionStored && $stackId === $firstStackId ? $card->order + 1 : $card->order;
			$downloaded = $this->downloadCardFiles($card, $credentials, $warnings, $downloadedBytes);
			try {
				$created = $cardService->create(
					$card->title,
					$stackId,
					'plain',
					$order,
					$userId,
					$this->description($card, $unmapped, array_keys($downloaded), !$deckChecklists),
					$card->due === null ? null : gmdate('Y-m-d H:i:s', $card->due),
				);
				$cardId = (int)$created->getId();
				$cardsCreated++;
			} catch (\Throwable $e) {
				$warnings[] = new ImportWarning('card_failed', [
					'title' => $card->title,
					'message' => $e->getMessage(),
				]);
				continue;
			}

			foreach ($downloaded as $key => $content) {
				$attachment = $this->attachmentByKey($card, $key);
				if ($attachment === null) {
					continue;
				}
				try {
					$this->storeAttachment($cardId, $userId, $attachment, $content);
					$attachmentsImported++;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('attachment_failed', [
						'title' => $card->title,
						'name' => $attachment->name !== '' ? $attachment->name : 'attachment',
						'message' => $this->safeMessage($e),
					]);
				}
			}

			foreach ($card->labelIds as $trelloLabelId) {
				if (!isset($labelIds[$trelloLabelId])) {
					continue;
				}
				try {
					$cardService->assignLabel($cardId, $labelIds[$trelloLabelId]);
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('label_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}

			foreach ($assignees as $uid => $member) {
				try {
					$assignmentService->assignUser($cardId, $uid);
					$assignedUsers++;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('assign_failed', [
						'user' => $uid,
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}

			foreach ($card->comments as $comment) {
				try {
					$this->createComment($cardId, $userId, $comment);
					$commentsCreated++;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('comment_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}

			if ($deckChecklists) {
				$this->writeDeckChecklists($cardId, $card, $userId, $warnings);
			}

			if ($card->archived) {
				try {
					$cardService->archive($cardId);
					$archivedCards++;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('archive_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}
		}

		return new ImportResult(
			$boardId,
			$parsed->title,
			$stacksCreated,
			$labelsCreated,
			$cardsCreated,
			$archivedCards,
			$commentsCreated,
			$assignedUsers,
			$parsed->skippedClosedCards + $parsed->skippedMissingListCards,
			$descriptionStored,
			$attachmentsImported,
			$warnings,
		);
	}

	public function attachToExisting(ParsedBoard $parsed, string $userId, int $boardId, TrelloCredentials $credentials, bool $useDeckChecklists = false): ImportResult {
		if (!class_exists(\OCA\Deck\Service\BoardService::class)) {
			throw new \RuntimeException('The Deck app must be installed and enabled.');
		}
		$permissionService = \OCP\Server::get(\OCA\Deck\Service\PermissionService::class);
		$permissionService->setUserId($userId);
		$boardService = \OCP\Server::get(\OCA\Deck\Service\BoardService::class);
		try {
			$board = $boardService->find($boardId, true, false);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException('The selected Deck board was not found.');
		}

		$warnings = [];
		$deckChecklists = $this->deckChecklistEnabled($useDeckChecklists, $warnings);
		$cardService = \OCP\Server::get(\OCA\Deck\Service\CardService::class);
		$buckets = $this->deckCardBuckets($boardId);
		$listTitles = [];
		foreach ($parsed->lists as $list) {
			$listTitles[$list->id] = $list->title;
		}
		$used = [];
		$attachmentsImported = 0;
		$commentsCreated = 0;
		$downloadedBytes = 0;
		$targets = [];
		foreach ($parsed->cards as $card) {
			$key = $this->matchKey(
				$listTitles[$card->listId] ?? '',
				$card->title,
				$card->archived,
			);
			$index = $used[$key] ?? 0;
			$used[$key] = $index + 1;
			$targets[] = [$card, $buckets[$key][$index] ?? null];
		}

		foreach ($targets as [$card, $cardId]) {
			$wantsChecklists = $deckChecklists && $card->checklists !== [];
			if (!$this->cardHasDownloadable($card) && $card->comments === [] && !$wantsChecklists) {
				continue;
			}
			if ($cardId === null) {
				$warnings[] = new ImportWarning('attachment_unmatched', ['title' => $card->title]);
				continue;
			}
			if ($wantsChecklists) {
				$this->writeDeckChecklists($cardId, $card, $userId, $warnings, $card->archived ? $cardService : null);
			}
			$existingComments = $this->existingCommentMessages($cardId);
			foreach ($card->comments as $comment) {
				$message = $this->commentMessage($comment);
				if (isset($existingComments[$message])) {
					continue;
				}
				try {
					$this->createComment($cardId, $userId, $comment);
					$commentsCreated++;
					$existingComments[$message] = true;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('comment_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}
			$downloaded = $this->downloadCardFiles($card, $credentials, $warnings, $downloadedBytes);
			foreach ($downloaded as $key => $content) {
				$attachment = $this->attachmentByKey($card, $key);
				if ($attachment === null) {
					continue;
				}
				try {
					$this->storeAttachment($cardId, $userId, $attachment, $content);
					$attachmentsImported++;
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('attachment_failed', [
						'title' => $card->title,
						'name' => $attachment->name !== '' ? $attachment->name : 'attachment',
						'message' => $this->safeMessage($e),
					]);
				}
			}
		}

		return new ImportResult(
			$boardId,
			(string)$board->getTitle(),
			0,
			0,
			0,
			0,
			$commentsCreated,
			0,
			0,
			false,
			$attachmentsImported,
			$warnings,
		);
	}

	/**
	 * @param array<string, string|null> $cache
	 * @param list<ImportWarning> $warnings
	 */
	private function resolveUser(ParsedMember $member, array $userMap, array &$cache, array &$warnings): ?string {
		$key = mb_strtolower($member->username);
		if (array_key_exists($key, $cache)) {
			return $cache[$key];
		}
		if (isset($userMap[$key])) {
			$mapped = $userMap[$key];
			if ($this->userManager->get($mapped) === null) {
				$warnings[] = new ImportWarning('user_not_found', ['user' => $mapped]);
				$cache[$key] = null;
				return null;
			}
			$cache[$key] = $mapped;
			return $mapped;
		}
		$user = $this->userManager->get($member->username) ?? $this->userManager->get($key);
		if ($user === null) {
			$warnings[] = new ImportWarning('member_unmapped', ['user' => $member->display()]);
			$cache[$key] = null;
			return null;
		}
		$cache[$key] = $user->getUID();
		return $cache[$key];
	}

	/**
	 * @param list<string> $unmapped
	 * @param list<string> $savedAttachmentKeys
	 */
	/**
	 * @param list<ImportWarning> $warnings
	 */
	private function deckChecklistEnabled(bool $requested, array &$warnings): bool {
		if (!$requested) {
			return false;
		}
		$enabled = false;
		try {
			$enabled = \OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('deckchecklist');
		} catch (\Throwable) {
			$enabled = false;
		}
		if (!$enabled) {
			$warnings[] = new ImportWarning('checklist_app_missing');
		}
		return $enabled;
	}

	/**
	 * @param list<ImportWarning> $warnings
	 */
	private function writeDeckChecklists(int $cardId, ParsedCard $card, string $userId, array &$warnings, ?object $cardService = null): void {
		if ($card->checklists === []) {
			return;
		}
		$restoreArchive = false;
		if ($cardService !== null && $card->archived) {
			try {
				$cardService->unarchive($cardId);
				$restoreArchive = true;
			} catch (\Throwable $e) {
				$warnings[] = new ImportWarning('checklist_failed', [
					'title' => $card->title,
					'message' => $e->getMessage(),
				]);
				return;
			}
		}
		try {
			$this->storeDeckChecklists($cardId, $card, $userId, $warnings);
		} finally {
			if ($restoreArchive && $cardService !== null) {
				try {
					$cardService->archive($cardId);
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('archive_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}
		}
	}

	/**
	 * @param list<ImportWarning> $warnings
	 */
	private function storeDeckChecklists(int $cardId, ParsedCard $card, string $userId, array &$warnings): void {
		if (!class_exists(\OCA\DeckChecklist\Service\ChecklistService::class)) {
			$warnings[] = new ImportWarning('checklist_app_missing');
			return;
		}
		\OCP\Server::get(\OCA\Deck\Service\PermissionService::class)->setUserId($userId);
		$service = \OCP\Server::get(\OCA\DeckChecklist\Service\ChecklistService::class);
		$used = [];
		try {
			foreach ($service->getCard($cardId)['checklists'] ?? [] as $list) {
				$used[(string)($list['title'] ?? '')] = true;
			}
		} catch (\Throwable $e) {
			$warnings[] = new ImportWarning('checklist_failed', [
				'title' => $card->title,
				'message' => $e->getMessage(),
			]);
			return;
		}
		$preexisting = $used;
		foreach ($card->checklists as $checklist) {
			$base = $this->clipChecklistText($checklist->title, 255, 'Checklist');
			if (isset($preexisting[$base])) {
				continue;
			}
			$title = $this->uniqueChecklistTitle($base, 255, 'Checklist', $used);
			try {
				$payload = $service->createList($cardId, $title);
			} catch (\Throwable $e) {
				$warnings[] = new ImportWarning('checklist_failed', [
					'title' => $card->title,
					'message' => $e->getMessage(),
				]);
				continue;
			}
			$used[$title] = true;
			$listId = $this->newestMatchingId($payload['checklists'] ?? [], $title);
			if ($listId === null) {
				continue;
			}
			foreach ($checklist->items as $item) {
				$itemTitle = $this->clipChecklistText($item->title, 1000, 'Item');
				try {
					$created = $service->createItem($listId, $itemTitle);
					if (!$item->checked) {
						continue;
					}
					$itemId = $this->newestItemId($created['checklists'] ?? [], $listId, $itemTitle);
					if ($itemId !== null) {
						$service->updateItem($itemId, $itemTitle, true, false, null);
					}
				} catch (\Throwable $e) {
					$warnings[] = new ImportWarning('checklist_failed', [
						'title' => $card->title,
						'message' => $e->getMessage(),
					]);
				}
			}
		}
	}

	/**
	 * @param array<string, true> $used
	 */
	private function uniqueChecklistTitle(string $title, int $max, string $fallback, array &$used): string {
		$base = $this->clipChecklistText($title, $max, $fallback);
		$candidate = $base;
		$n = 2;
		while (isset($used[$candidate])) {
			$suffix = ' (' . $n . ')';
			$candidate = $this->clipChecklistText($base, $max - mb_strlen($suffix), $fallback) . $suffix;
			$n++;
		}
		return $candidate;
	}

	private function clipChecklistText(string $value, int $max, string $fallback): string {
		$value = trim(str_replace(["\r", "\n"], ' ', $value));
		if ($value === '') {
			$value = $fallback;
		}
		if (mb_strlen($value) > $max) {
			$value = mb_substr($value, 0, $max);
		}
		return $value;
	}

	/**
	 * @param list<array<string, mixed>> $lists
	 */
	private function newestMatchingId(array $lists, string $title): ?int {
		$id = null;
		foreach ($lists as $list) {
			if (($list['title'] ?? '') !== $title) {
				continue;
			}
			$candidate = (int)($list['id'] ?? 0);
			if ($candidate > 0 && ($id === null || $candidate > $id)) {
				$id = $candidate;
			}
		}
		return $id;
	}

	/**
	 * @param list<array<string, mixed>> $lists
	 */
	private function newestItemId(array $lists, int $listId, string $title): ?int {
		$id = null;
		foreach ($lists as $list) {
			if ((int)($list['id'] ?? 0) !== $listId) {
				continue;
			}
			foreach ($list['items'] ?? [] as $item) {
				if (($item['title'] ?? '') !== $title) {
					continue;
				}
				$candidate = (int)($item['id'] ?? 0);
				if ($candidate > 0 && ($id === null || $candidate > $id)) {
					$id = $candidate;
				}
			}
		}
		return $id;
	}

	private function description(ParsedCard $card, array $unmapped, array $savedAttachmentKeys = [], bool $includeChecklists = true): string {
		$parts = [];
		if (trim($card->description) !== '') {
			$parts[] = rtrim($card->description);
		}
		if ($includeChecklists && $card->checklistMarkdown !== '') {
			$parts[] = $card->checklistMarkdown;
		}
		$attachmentMarkdown = $this->attachmentMarkdown($card, $savedAttachmentKeys);
		if ($attachmentMarkdown !== '') {
			$parts[] = $attachmentMarkdown;
		}
		if ($unmapped !== []) {
			$parts[] = 'Membres Trello : ' . implode(', ', $unmapped);
		}
		return implode("\n\n", $parts);
	}

	/**
	 * @return array<string, true>
	 */
	private function existingCommentMessages(int $cardId): array {
		$messages = [];
		foreach ($this->commentsManager->getForObject('deckCard', (string)$cardId) as $comment) {
			$messages[$comment->getMessage()] = true;
		}
		return $messages;
	}

	private function commentMessage(ParsedComment $comment): string {
		$date = $comment->date;
		try {
			$date = (new \DateTimeImmutable($comment->date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
		} catch (\Exception) {
			// Keep the original date string when it is not a timestamp.
		}
		$message = $comment->author . ' (' . $date . "):\n" . $comment->message;
		if (mb_strlen($message) > self::COMMENT_MAX) {
			$message = mb_substr($message, 0, self::COMMENT_MAX - 1) . '…';
		}
		return $message;
	}

	private function createComment(int $cardId, string $userId, ParsedComment $comment): void {
		$message = $this->commentMessage($comment);
		$created = $this->commentsManager->create('users', $userId, 'deckCard', (string)$cardId);
		$created->setMessage($message);
		$created->setVerb('comment');
		$this->commentsManager->save($created);
	}

	private function removeDefaultLabels(object $boardService, object $labelService, int $boardId): void {
		$created = $boardService->find($boardId);
		foreach ($created->getLabels() ?? [] as $label) {
			try {
				$labelService->delete((int)$label->getId());
			} catch (\Throwable) {
				// Deck can keep a default label when deletion is rejected.
			}
		}
	}

	/**
	 * @param list<string> $savedKeys
	 */
	private function attachmentMarkdown(ParsedCard $card, array $savedKeys): string {
		if ($savedKeys === []) {
			return $card->attachmentMarkdown;
		}
		$saved = array_fill_keys($savedKeys, true);
		$lines = [];
		foreach ($card->attachments as $attachment) {
			if (isset($saved[$this->attachmentKey($attachment)])) {
				continue;
			}
			$name = $attachment->name !== '' ? $attachment->name : $attachment->url;
			$name = str_replace(['[', ']'], ['(', ')'], str_replace(["\r", "\n"], ' ', $name));
			$lines[] = '- [' . $name . '](' . $attachment->url . ')';
		}
		if ($lines === []) {
			return '';
		}
		return "## Pièces jointes\n" . implode("\n", $lines);
	}

	/**
	 * @param list<ImportWarning> $warnings
	 * @return array<string, string>
	 */
	private function downloadCardFiles(ParsedCard $card, ?TrelloCredentials $credentials, array &$warnings, int &$downloadedBytes): array {
		if ($credentials === null) {
			return [];
		}
		@set_time_limit(300);
		$downloaded = [];
		foreach ($card->attachments as $attachment) {
			if (!$attachment->isDownloadable()) {
				continue;
			}
			if ($downloadedBytes >= self::DOWNLOAD_TOTAL_MAX) {
				$warnings[] = new ImportWarning('attachment_failed', [
					'title' => $card->title,
					'name' => $attachment->name !== '' ? $attachment->name : 'attachment',
					'message' => 'The total size of downloaded files reached 200 MB.',
				]);
				continue;
			}
			try {
				$content = $this->attachmentClient->download($attachment->url, $credentials);
			} catch (\Throwable $e) {
				$warnings[] = new ImportWarning('attachment_failed', [
					'title' => $card->title,
					'name' => $attachment->name !== '' ? $attachment->name : 'attachment',
					'message' => $this->safeMessage($e),
				]);
				continue;
			}
			$downloadedBytes += strlen($content);
			$downloaded[$this->attachmentKey($attachment)] = $content;
		}
		return $downloaded;
	}

	private function storeAttachment(int $cardId, string $userId, ParsedAttachment $attachment, string $content): void {
		$fileName = AttachmentNamer::fileName($attachment);
		$entity = new \OCA\Deck\Db\Attachment();
		$entity->setCardId($cardId);
		$entity->setType('file');
		$entity->setData($fileName);
		$entity->setCreatedBy($userId);
		$entity->setLastModified(time());
		$entity->setCreatedAt(time());

		$attachmentService = \OCP\Server::get(\OCA\Deck\Service\AttachmentService::class);
		$fileService = $attachmentService->getService('file');
		if (!$fileService instanceof \OCA\Deck\Service\FilesAppService) {
			throw new \RuntimeException('Deck file attachments are not available.');
		}
		$fileService->createFromImport($entity, $content);
		$fileService->extendData($entity);
		$attachmentService->syncAttachmentCreateSideEffects($entity);
	}

	private function attachmentByKey(ParsedCard $card, string $key): ?ParsedAttachment {
		foreach ($card->attachments as $attachment) {
			if ($this->attachmentKey($attachment) === $key) {
				return $attachment;
			}
		}
		return null;
	}

	private function attachmentKey(ParsedAttachment $attachment): string {
		return $attachment->id !== '' ? $attachment->id : 'url:' . $attachment->url;
	}

	private function cardHasDownloadable(ParsedCard $card): bool {
		foreach ($card->attachments as $attachment) {
			if ($attachment->isDownloadable()) {
				return true;
			}
		}
		return false;
	}

	private function countDownloadable(ParsedBoard $parsed): int {
		$count = 0;
		foreach ($parsed->cards as $card) {
			foreach ($card->attachments as $attachment) {
				if ($attachment->isDownloadable()) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * @return array<string, list<int>>
	 */
	private function deckCardBuckets(int $boardId): array {
		$stackMapper = \OCP\Server::get(\OCA\Deck\Db\StackMapper::class);
		$cardMapper = \OCP\Server::get(\OCA\Deck\Db\CardMapper::class);
		$stacks = $stackMapper->findAll($boardId);
		$stackIds = [];
		$titles = [];
		foreach ($stacks as $stack) {
			$id = (int)$stack->getId();
			$stackIds[] = $id;
			$titles[$id] = (string)$stack->getTitle();
		}
		if ($stackIds === []) {
			return [];
		}
		$active = $cardMapper->findAllForStacks($stackIds);
		$archived = $cardMapper->findAllArchivedForStacks($stackIds);
		$buckets = [];
		foreach ($stackIds as $stackId) {
			$cards = array_merge($active[$stackId] ?? [], $archived[$stackId] ?? []);
			usort($cards, static fn (object $a, object $b): int => ((int)$a->getOrder()) <=> ((int)$b->getOrder()));
			foreach ($cards as $card) {
				$key = $this->matchKey($titles[$stackId], (string)$card->getTitle(), (bool)$card->getArchived());
				$buckets[$key][] = (int)$card->getId();
			}
		}
		return $buckets;
	}

	private function matchKey(string $stack, string $title, bool $archived): string {
		return $stack . "\n" . $title . "\n" . ($archived ? '1' : '0');
	}

	private function safeMessage(\Throwable $e): string {
		$message = preg_replace('/(key|token)=[^&\s]+/i', '$1=***', $e->getMessage()) ?? $e->getMessage();
		if (mb_strlen($message) > 180) {
			$message = mb_substr($message, 0, 179) . '…';
		}
		return $message;
	}

	private function findLabelId(object $boardService, int $boardId, string $title): ?int {
		$board = $boardService->find($boardId);
		foreach ($board->getLabels() ?? [] as $label) {
			if ($label->getTitle() === $title) {
				return (int)$label->getId();
			}
		}
		return null;
	}
}
