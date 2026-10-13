<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\ImportWarning;
use OCP\IL10N;

class WarningTranslator {
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	public function translate(ImportWarning $warning): string {
		$count = (int)($warning->params['count'] ?? 0);
		$user = (string)($warning->params['user'] ?? '');
		$title = (string)($warning->params['title'] ?? '');
		$name = (string)($warning->params['name'] ?? '');
		$message = (string)($warning->params['message'] ?? '');

		return match ($warning->code) {
			'actions_truncated' => $this->l10n->t('The Trello export contains 1000 actions, which is the export limit. Older comments may be missing.'),
			'skipped_closed' => $this->l10n->n(
				'Skipped %n card because its list is closed.',
				'Skipped %n cards because their list is closed.',
				$count
			),
			'skipped_missing_list' => $this->l10n->n(
				'Skipped %n card because its list is missing.',
				'Skipped %n cards because their list is missing.',
				$count
			),
			'titles_truncated' => $this->l10n->n(
				'Truncated %n card title to 255 characters.',
				'Truncated %n card titles to 255 characters.',
				$count
			),
			'board_title_truncated' => $this->l10n->t('Truncated the board title to 100 characters.'),
			'board_description' => $this->l10n->t('Deck boards have no description. The Trello board description will be stored as a card named "Description" on the first list.'),
			'user_not_found' => $this->l10n->t('Nextcloud user "%s" was not found. The Trello member stays in the card description.', [$user]),
			'member_unmapped' => $this->l10n->t('Trello member "%s" does not match a Nextcloud user and is listed on the card.', [$user]),
			'assign_failed' => $this->l10n->t('Could not assign "%1$s" on "%2$s": %3$s', [$user, $title, $message]),
			'share_failed' => $this->l10n->t('Could not share the board with "%1$s": %2$s', [$user, $message]),
			'card_failed' => $this->l10n->t('Could not create card "%1$s": %2$s', [$title, $message]),
			'label_failed' => $this->l10n->t('Could not create label "%1$s": %2$s', [$title, $message]),
			'comment_failed' => $this->l10n->t('Could not create a comment on "%1$s": %2$s', [$title, $message]),
			'archive_failed' => $this->l10n->t('Could not archive card "%1$s": %2$s', [$title, $message]),
			'stack_failed' => $this->l10n->t('Could not create list "%1$s": %2$s', [$title, $message]),
			'no_list_for_description' => $this->l10n->t('The Trello board description was not imported because the board has no list.'),
			'checklist_app_missing' => $this->l10n->t('Deck Checklists is not enabled. Checklists stay in the card description.'),
			'checklist_failed' => $this->l10n->t('Could not create a checklist on "%1$s": %2$s', [$title, $message]),
			'attachments_need_token' => $this->l10n->n(
				'%n Trello file was kept as a link. Add a Trello API key and token to download it.',
				'%n Trello files were kept as links. Add a Trello API key and token to download them.',
				$count
			),
			'attachment_failed' => $this->l10n->t('Could not download "%1$s" on "%2$s": %3$s', [$name, $title, $message]),
			'attachment_unmatched' => $this->l10n->t('Could not find card "%s" on the selected Deck board.', [$title]),
			default => $message !== '' ? $warning->code . ': ' . $message : $warning->code,
		};
	}

	/**
	 * @param list<ImportWarning> $warnings
	 * @return list<string>
	 */
	public function translateAll(array $warnings): array {
		return array_map($this->translate(...), $warnings);
	}
}
