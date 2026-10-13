<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Controller;

use OCA\DeckTrello\AppInfo\Application;
use OCA\DeckTrello\Model\ParsedBoard;
use OCA\DeckTrello\Model\TrelloCredentials;
use OCA\DeckTrello\Service\ApiKey;
use OCA\DeckTrello\Service\DeckWriter;
use OCA\DeckTrello\Service\TrelloBoardClient;
use OCA\DeckTrello\Service\TrelloParser;
use OCA\DeckTrello\Service\UserMap;
use OCA\DeckTrello\Service\WarningTranslator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class ImportController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly TrelloParser $parser,
		private readonly TrelloBoardClient $boards,
		private readonly DeckWriter $writer,
		private readonly WarningTranslator $translator,
		private readonly ApiKey $apiKey,
		private readonly IL10N $l10n,
		private readonly ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function preview(): DataResponse {
		try {
			$parsed = $this->parsedBoard();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $this->publicError($e)], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->previewPayload($parsed));
	}

	#[NoAdminRequired]
	public function import(): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return new DataResponse(['message' => $this->l10n->t('You must be logged in to import a board.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$parsed = $this->parsedBoard();
			$userMap = UserMap::fromText((string)$this->request->getParam('userMap', ''));
			$credentials = $this->credentials();
			$useDeckChecklists = $this->flag('useDeckChecklist');
			if ($this->flag('attachExisting')) {
				if ($credentials === null) {
					throw new \InvalidArgumentException('A Trello API key and token are required to download attachments.');
				}
				$boardId = (int)$this->request->getParam('boardId', 0);
				if ($boardId <= 0) {
					throw new \InvalidArgumentException('Select the Deck board that should receive the files.');
				}
				$result = $this->writer->attachToExisting($parsed, $this->userId, $boardId, $credentials, $useDeckChecklists);
			} else {
				$result = $this->writer->import($parsed, $this->userId, $userMap, $credentials, $useDeckChecklists);
			}
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $this->publicError($e)], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $this->l10n->t('The Deck app must be installed and enabled.')], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$payload = $result->toArray();
		$payload['warnings'] = $this->translator->translateAll($result->warnings);
		return new DataResponse($payload);
	}

	private function parsedBoard(): ParsedBoard {
		$includeClosed = $this->flag('includeClosedLists');
		$board = trim((string)$this->request->getParam('trelloBoard', ''));
		if ($board !== '') {
			$credentials = $this->credentials();
			if ($credentials === null) {
				throw new \InvalidArgumentException('A Trello API key and token are required to read the board from the API.');
			}
			$fetched = $this->boards->fetch($board, $credentials);
			return $this->parser->parse($fetched['json'], $includeClosed, $fetched['complete']);
		}

		return $this->parser->parse($this->uploadedJson(), $includeClosed);
	}

	private function uploadedJson(): string {
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file)) {
			throw new \InvalidArgumentException('No file was uploaded.');
		}
		$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			throw new \InvalidArgumentException('The file is larger than the server upload limit.');
		}
		if ($error !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException('No file was uploaded.');
		}
		$contents = file_get_contents((string)$file['tmp_name']);
		if ($contents === false) {
			throw new \InvalidArgumentException('Could not read the uploaded file.');
		}
		return $contents;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function previewPayload(ParsedBoard $parsed): array {
		return [
			'board' => $parsed->title,
			'lists' => count($parsed->lists),
			'cards' => count($parsed->cards),
			'archivedCards' => $parsed->archivedCards,
			'labels' => count($parsed->labels),
			'checklists' => $parsed->checklists,
			'comments' => $parsed->comments,
			'attachments' => $parsed->attachments,
			'members' => $parsed->members,
			'trelloMembers' => $parsed->memberProfiles,
			'skippedCards' => $parsed->skippedClosedCards + $parsed->skippedMissingListCards,
			'actionsTruncated' => $parsed->actionsTruncated,
			'warnings' => $this->translator->translateAll($parsed->warnings),
		];
	}

	private function credentials(): ?TrelloCredentials {
		$typedKey = trim((string)$this->request->getParam('trelloKey', ''));
		$token = trim((string)$this->request->getParam('trelloToken', ''));
		if ($typedKey === '' && $token === '') {
			return null;
		}
		$key = $this->apiKey->resolve($typedKey);
		if ($key === '' || $token === '') {
			throw new \InvalidArgumentException('Both the Trello API key and the token are required to download attachments.');
		}
		return new TrelloCredentials($key, $token);
	}

	private function flag(string $name): bool {
		$value = $this->request->getParam($name);
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}

	private function publicError(\InvalidArgumentException $e): string {
		if (str_starts_with($e->getMessage(), 'Trello returned HTTP ')) {
			return $this->l10n->t('Could not read the Trello board.');
		}
		return match ($e->getMessage()) {
			'The file is not valid JSON.' => $this->l10n->t('The file is not valid JSON.'),
			'The file is not a Trello board export.' => $this->l10n->t('The file is not a Trello board export.'),
			'Could not read the uploaded file.' => $this->l10n->t('Could not read the uploaded file.'),
			'No file was uploaded.' => $this->l10n->t('Choose a Trello JSON file or a Trello board URL.'),
			'The Trello board URL is invalid.' => $this->l10n->t('The Trello board URL is invalid.'),
			'A Trello API key and token are required to read the board from the API.' => $this->l10n->t('A Trello API key and token are required to read the board from the API.'),
			'Trello refused the request. Check the API key, the token, and that the token can read this board.' => $this->l10n->t('Trello refused the request. Check the API key, the token, and that the token can read this board.'),
			'The Trello board was not found.' => $this->l10n->t('The Trello board was not found.'),
			'Could not read the Trello board.' => $this->l10n->t('Could not read the Trello board.'),
			'Trello returned an empty response.' => $this->l10n->t('Could not read the Trello board.'),
			'Trello returned a board that could not be read.' => $this->l10n->t('Could not read the Trello board.'),
			'Trello returned a comment history that could not be read.' => $this->l10n->t('Could not read the Trello board.'),
			'Trello returned a response that is not valid JSON.' => $this->l10n->t('Could not read the Trello board.'),
			'Trello returned a response that could not be read.' => $this->l10n->t('Could not read the Trello board.'),
			'The Trello board is larger than 40 MB.' => $this->l10n->t('The Trello board is larger than 40 MB.'),
			'The file is larger than the server upload limit.' => $this->l10n->t('The file is larger than the server upload limit.'),
			'The Trello API key or token is invalid.' => $this->l10n->t('The Trello API key or token is invalid.'),
			'Both the Trello API key and the token are required to download attachments.' => $this->l10n->t('Both the Trello API key and the token are required to download attachments.'),
			'A Trello API key and token are required to download attachments.' => $this->l10n->t('A Trello API key and token are required to download attachments.'),
			'Select the Deck board that should receive the files.' => $this->l10n->t('Select the Deck board that should receive the files.'),
			'The selected Deck board was not found.' => $this->l10n->t('The selected Deck board was not found.'),
			default => $this->l10n->t('Invalid user mapping. Expected trelloUsername=nextcloudUid.'),
		};
	}
}
