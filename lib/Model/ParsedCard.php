<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Model;

class ParsedCard {
	/**
	 * @param list<string> $labelIds
	 * @param list<ParsedMember> $members
	 * @param list<ParsedComment> $comments
	 * @param list<ParsedAttachment> $attachments
	 * @param list<ParsedChecklist> $checklists
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly string $checklistMarkdown,
		public readonly string $attachmentMarkdown,
		public readonly ?int $due,
		public readonly int $order,
		public readonly bool $archived,
		public readonly string $listId,
		public readonly array $labelIds,
		public readonly array $members,
		public readonly array $comments,
		public readonly array $attachments = [],
		public readonly array $checklists = [],
	) {
	}
}
