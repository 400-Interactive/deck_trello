<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ImportResult {
	/**
	 * @param list<ImportWarning> $warnings
	 */
	public function __construct(
		public readonly int $boardId,
		public readonly string $boardTitle,
		public readonly int $stacks,
		public readonly int $labels,
		public readonly int $cards,
		public readonly int $archivedCards,
		public readonly int $comments,
		public readonly int $assignedUsers,
		public readonly int $skippedCards,
		public readonly bool $boardDescriptionStoredAsCard,
		public readonly int $attachmentsImported,
		public readonly array $warnings,
	) {
	}

	/**
	 * @return array<string, int|string|bool|list<array{code: string, params: array<string, int|string>}>>
	 */
	public function toArray(): array {
		return [
			'boardId' => $this->boardId,
			'boardTitle' => $this->boardTitle,
			'stacks' => $this->stacks,
			'labels' => $this->labels,
			'cards' => $this->cards,
			'archivedCards' => $this->archivedCards,
			'comments' => $this->comments,
			'assignedUsers' => $this->assignedUsers,
			'skippedCards' => $this->skippedCards,
			'boardDescriptionStoredAsCard' => $this->boardDescriptionStoredAsCard,
			'attachmentsImported' => $this->attachmentsImported,
			'warnings' => array_map(static fn (ImportWarning $warning): array => $warning->toArray(), $this->warnings),
		];
	}
}
