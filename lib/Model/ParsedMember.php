<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedMember {
	public function __construct(
		public readonly string $username,
		public readonly string $fullName,
	) {
	}

	public function display(): string {
		if ($this->fullName !== '' && $this->fullName !== $this->username) {
			return $this->fullName . ' (@' . $this->username . ')';
		}
		return $this->username;
	}
}
