<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedLabel {
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $color,
	) {
	}
}
