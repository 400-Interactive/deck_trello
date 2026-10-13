<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedAttachment {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $url,
		public readonly string $mimeType,
		public readonly int $bytes,
		public readonly bool $uploaded,
	) {
	}

	public function isDownloadable(): bool {
		if (!$this->uploaded) {
			return false;
		}
		$parts = parse_url($this->url);
		if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}
		$host = strtolower((string)($parts['host'] ?? ''));
		return $host === 'trello.com' || $host === 'api.trello.com';
	}
}
