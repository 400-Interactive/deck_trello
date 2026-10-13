<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedList {
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly int $order,
		public readonly bool $closed,
	) {
	}
}
