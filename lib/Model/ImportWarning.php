<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ImportWarning {
	/**
	 * @param array<string, int|string> $params
	 */
	public function __construct(
		public readonly string $code,
		public readonly array $params = [],
	) {
	}

	/**
	 * @return array{code: string, params: array<string, int|string>}
	 */
	public function toArray(): array {
		return [
			'code' => $this->code,
			'params' => $this->params,
		];
	}
}
