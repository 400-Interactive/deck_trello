<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedChecklistItem {
	public function __construct(
		public readonly string $title,
		public readonly bool $checked,
	) {
	}
}
