<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class TrelloCredentials {
	public function __construct(
		public readonly string $key,
		public readonly string $token,
	) {
		if (!preg_match('/^[A-Za-z0-9]{8,128}$/', $key) || !preg_match('/^[A-Za-z0-9]{8,128}$/', $token)) {
			throw new \InvalidArgumentException('The Trello API key or token is invalid.');
		}
	}
}
