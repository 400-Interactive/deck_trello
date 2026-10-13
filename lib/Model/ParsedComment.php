<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedComment {
	public function __construct(
		public readonly string $author,
		public readonly string $date,
		public readonly string $message,
	) {
	}
}
