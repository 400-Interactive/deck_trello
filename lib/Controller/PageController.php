<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Controller;

use OCA\DeckTrello\AppInfo\Application;
use OCA\DeckTrello\Service\ApiKey;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly ApiKey $apiKey,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'index', [
			'previewUrl' => $this->urlGenerator->linkToRoute('deck_trello.import.preview'),
			'importUrl' => $this->urlGenerator->linkToRoute('deck_trello.import.import'),
			'boards' => $this->boards(),
			'deckChecklist' => $this->deckChecklistEnabled(),
			'apiKey' => $this->apiKey->configured(),
		]);
	}

	private function deckChecklistEnabled(): bool {
		try {
			return \OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('deckchecklist');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{id: int, title: string}>
	 */
	private function boards(): array {
		try {
			if (!class_exists(\OCA\Deck\Service\BoardService::class)) {
				return [];
			}
			$service = \OCP\Server::get(\OCA\Deck\Service\BoardService::class);
			$boards = [];
			foreach ($service->findAll(-1, false, false) as $board) {
				$boards[] = [
					'id' => (int)$board->getId(),
					'title' => (string)$board->getTitle(),
				];
			}
			usort($boards, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
			return $boards;
		} catch (\Throwable) {
			return [];
		}
	}
}
