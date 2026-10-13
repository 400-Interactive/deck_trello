<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedBoard {
	/**
	 * @param list<ParsedList> $lists
	 * @param list<ParsedLabel> $labels
	 * @param list<ParsedCard> $cards
	 * @param list<string> $members
	 * @param list<ImportWarning> $warnings
	 * @param list<array{username: string, fullName: string}> $memberProfiles
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly array $lists,
		public readonly array $labels,
		public readonly array $cards,
		public readonly int $archivedCards,
		public readonly int $checklists,
		public readonly int $comments,
		public readonly int $attachments,
		public readonly array $members,
		public readonly int $skippedClosedCards,
		public readonly int $skippedMissingListCards,
		public readonly bool $actionsTruncated,
		public readonly array $warnings,
		public readonly array $memberProfiles = [],
	) {
	}
}
