<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedChecklist {
	/**
	 * @param list<ParsedChecklistItem> $items
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $items,
	) {
	}
}
