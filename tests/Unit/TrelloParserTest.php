<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Tests\Unit;

use OCA\DeckTrello\Service\TrelloParser;
use OCA\DeckTrello\Service\UserMap;
use PHPUnit\Framework\TestCase;

class TrelloParserTest extends TestCase {
	private TrelloParser $parser;

	protected function setUp(): void {
		$this->parser = new TrelloParser();
	}

	public function testParsesReducedBoard(): void {
		$board = $this->parser->parse($this->fixture());

		self::assertSame('Final Night At Hotel', $board->title);
		self::assertSame('Nuit de test', $board->description);
		self::assertSame(['list-doing', 'list-todo'], array_map(static fn ($list) => $list->id, $board->lists));
		self::assertSame([0, 1], array_map(static fn ($list) => $list->order, $board->lists));
		self::assertFalse($board->lists[0]->closed);

		self::assertCount(3, $board->cards);
		$shader = $this->cardNamed($board->cards, 'Gerer le chargement des shaders');
		$archived = $this->cardNamed($board->cards, 'Carte archivée');
		$long = null;
		foreach ($board->cards as $card) {
			if (str_starts_with($card->title, 'Titre beaucoup trop long')) {
				$long = $card;
			}
		}
		self::assertNotNull($long);
		self::assertSame('list-doing', $shader->listId);
		self::assertSame(1, $shader->order);
		self::assertSame(0, $long->order);
		self::assertSame('list-todo', $archived->listId);
		self::assertTrue($archived->archived);
		self::assertSame(1, $board->archivedCards);
		self::assertSame(1, $board->skippedClosedCards);
		self::assertSame(1, $board->skippedMissingListCards);

		self::assertSame("Eviter les freezes.", $shader->description);
		self::assertSame(
			"## Fantome\n- [x] Faire un certains nombre de points...\n- [ ] Autre item",
			$shader->checklistMarkdown
		);
		self::assertCount(1, $shader->checklists);
		self::assertSame('Fantome', $shader->checklists[0]->title);
		self::assertTrue($shader->checklists[0]->items[0]->checked);
		self::assertSame('Faire un certains nombre de points...', $shader->checklists[0]->items[0]->title);
		self::assertFalse($shader->checklists[0]->items[1]->checked);
		self::assertStringContainsString(
			'- [image.png](https://trello.com/1/cards/abc/attachments/def/download/image.png)',
			$shader->attachmentMarkdown
		);
		self::assertSame(
			(new \DateTimeImmutable('2026-09-25T20:37:11.508Z'))->getTimestamp(),
			$shader->due
		);
		self::assertSame(['label-yellow', 'label-green'], $shader->labelIds);
		self::assertSame('kinj14', $shader->members[0]->username);
		self::assertSame('Première note', $shader->comments[0]->message);
		self::assertSame('Kinj14', $shader->comments[0]->author);
		self::assertSame('Deuxième note', $shader->comments[1]->message);

		$byId = [];
		foreach ($board->labels as $label) {
			$byId[$label->id] = $label;
		}
		self::assertSame('A TESTER', $byId['label-yellow']->title);
		self::assertSame('F1DB50', $byId['label-yellow']->color);
		self::assertSame('green', $byId['label-green']->title);
		self::assertSame('31CC7C', $byId['label-green']->color);
		self::assertSame('BUG', $byId['label-bug']->title);
		self::assertSame('FF7A66', $byId['label-bug']->color);
		self::assertSame('BUG (2)', $byId['label-bug-2']->title);
		self::assertSame('FF7A66', $byId['label-bug-2']->color);
		self::assertSame('317CCC', $byId['label-sky']->color);
		self::assertSame('31CC7C', $byId['label-lime']->color);
		self::assertSame('3A3B3D', $byId['label-black']->color);
		self::assertSame('CC317C', $byId['label-pink']->color);
		self::assertSame('7C31CC', $byId['label-purple']->color);
		self::assertSame('317CCC', $byId['label-blue']->color);

		self::assertSame(1, $board->checklists);
		self::assertSame(2, $board->comments);
		self::assertSame(1, $board->attachments);
		self::assertCount(1, $shader->attachments);
		self::assertSame('image.png', $shader->attachments[0]->name);
		self::assertTrue($shader->attachments[0]->uploaded);
		self::assertTrue($shader->attachments[0]->isDownloadable());
		self::assertSame(['kinj14', 'nathanmacaignereikoarts'], $board->members);
		self::assertSame('Nathan Macaigne', $board->memberProfiles[1]['fullName']);
		self::assertFalse($board->actionsTruncated);
		self::assertContains('board_description', array_map(static fn ($warning) => $warning->code, $board->warnings));
		self::assertContains('skipped_closed', array_map(static fn ($warning) => $warning->code, $board->warnings));
		self::assertContains('skipped_missing_list', array_map(static fn ($warning) => $warning->code, $board->warnings));
		self::assertSame(255, mb_strlen($long->title));
		self::assertStringEndsWith('…', $long->title);
	}

	public function testIncludeClosedLists(): void {
		$board = $this->parser->parse($this->fixture(), true);

		self::assertSame(
			['list-closed', 'list-doing', 'list-todo'],
			array_map(static fn ($list) => $list->id, $board->lists)
		);
		self::assertTrue($board->lists[0]->closed);
		self::assertCount(4, $board->cards);
		self::assertSame(0, $board->skippedClosedCards);
		self::assertSame(1, $board->skippedMissingListCards);
	}

	public function testActionLimitWarning(): void {
		$data = json_decode($this->fixture(), true, 512, JSON_THROW_ON_ERROR);
		$data['actions'] = [];
		for ($i = 0; $i < TrelloParser::ACTION_LIMIT; $i++) {
			$data['actions'][] = [
				'type' => 'updateCard',
				'date' => '2026-01-01T00:00:00.000Z',
				'data' => [],
			];
		}
		$board = $this->parser->parse(json_encode($data, JSON_THROW_ON_ERROR));

		self::assertTrue($board->actionsTruncated);
		self::assertSame(0, $board->comments);
		self::assertContains('actions_truncated', array_map(static fn ($warning) => $warning->code, $board->warnings));

		$complete = $this->parser->parse(json_encode($data, JSON_THROW_ON_ERROR), false, true);
		self::assertFalse($complete->actionsTruncated);
		self::assertNotContains('actions_truncated', array_map(static fn ($warning) => $warning->code, $complete->warnings));
	}

	public function testRejectsInvalidJson(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->parser->parse('{');
	}

	public function testUserMap(): void {
		$map = UserMap::fromText("# comment\n\nkinj14=admin\nNathan=nathan\n");
		self::assertSame([
			'kinj14' => 'admin',
			'nathan' => 'nathan',
		], $map);
	}

	public function testUserMapRejectsBrokenLine(): void {
		$this->expectException(\InvalidArgumentException::class);
		UserMap::fromText("kinj14 admin\n");
	}

	/**
	 * @param list<\OCA\DeckTrello\Model\ParsedCard> $cards
	 */
	private function cardNamed(array $cards, string $title): \OCA\DeckTrello\Model\ParsedCard {
		foreach ($cards as $card) {
			if ($card->title === $title) {
				return $card;
			}
		}
		self::fail('Missing card ' . $title);
	}

	private function fixture(): string {
		$path = dirname(__DIR__) . '/fixtures/mini-board.json';
		$json = file_get_contents($path);
		self::assertNotFalse($json);
		return $json;
	}
}
