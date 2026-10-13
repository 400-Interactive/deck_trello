<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\TrelloCredentials;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

class TrelloBoardClient {
	private const MAX_BYTES = 41943040;
	private const PAGE_SIZE = 1000;
	private const MAX_PAGES = 20;

	private readonly IClient $client;

	public function __construct(IClientService $clientService) {
		$this->client = $clientService->newClient();
	}

	public static function normalizeBoard(string $value): string {
		$value = trim($value);
		if (preg_match('#^https://trello\.com/b/([A-Za-z0-9]{8,24})(?:/|$)#', $value, $matches) === 1) {
			return $matches[1];
		}
		if (preg_match('/^[A-Za-z0-9]{8,24}$/', $value) === 1) {
			return $value;
		}
		throw new \InvalidArgumentException('The Trello board URL is invalid.');
	}

	/**
	 * @return array{json: string, complete: bool}
	 */
	public function fetch(string $board, TrelloCredentials $credentials): array {
		@set_time_limit(300);
		$id = self::normalizeBoard($board);
		$document = $this->getJson('/1/boards/' . rawurlencode($id), [
			'fields' => 'name,desc,closed',
			'cards' => 'all',
			'card_fields' => 'name,desc,closed,idList,idMembers,idLabels,due,pos',
			'card_attachments' => 'true',
			'card_attachment_fields' => 'name,url,bytes,mimeType,isUpload,fileName',
			'lists' => 'all',
			'list_fields' => 'name,closed,pos',
			'labels' => 'all',
			'label_fields' => 'name,color',
			'members' => 'all',
			'member_fields' => 'username,fullName',
			'checklists' => 'all',
			'checklist_fields' => 'name,idCard,pos',
		], $credentials);

		$history = $this->commentActions($id, $credentials);
		$document['actions'] = $history['actions'];
		$json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json) || $json === '') {
			throw new \InvalidArgumentException('Trello returned a board that could not be read.');
		}

		return [
			'json' => $json,
			'complete' => $history['complete'],
		];
	}

	/**
	 * @return array{actions: list<array<string, mixed>>, complete: bool}
	 */
	private function commentActions(string $id, TrelloCredentials $credentials): array {
		$actions = [];
		$before = null;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$query = [
				'filter' => 'commentCard',
				'limit' => (string)self::PAGE_SIZE,
				'memberCreator' => 'true',
				'memberCreator_fields' => 'fullName,username',
			];
			if ($before !== null) {
				$query['before'] = $before;
			}
			$batch = $this->getJson('/1/boards/' . rawurlencode($id) . '/actions', $query, $credentials);
			if (!array_is_list($batch)) {
				throw new \InvalidArgumentException('Trello returned a comment history that could not be read.');
			}
			foreach ($batch as $action) {
				if (is_array($action)) {
					$actions[] = $action;
				}
			}
			$count = count($batch);
			if ($count < self::PAGE_SIZE) {
				return ['actions' => $actions, 'complete' => true];
			}
			$last = $batch[$count - 1];
			$nextBefore = is_array($last) ? (string)($last['id'] ?? '') : '';
			if ($nextBefore === '' || $nextBefore === $before) {
				break;
			}
			$before = $nextBefore;
		}

		return ['actions' => $actions, 'complete' => false];
	}

	/**
	 * @param array<string, string> $query
	 * @return array<string, mixed>
	 */
	private function getJson(string $path, array $query, TrelloCredentials $credentials): array {
		if (!str_starts_with($path, '/1/') || str_contains($path, '..')) {
			throw new \InvalidArgumentException('The Trello board URL is invalid.');
		}
		$query['key'] = $credentials->key;
		$query['token'] = $credentials->token;
		$url = 'https://api.trello.com' . $path . '?' . http_build_query($query);
		try {
			$response = $this->client->get($url, [
				'timeout' => 60,
				'allow_redirects' => false,
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud Deck Trello Import',
				],
			]);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException($this->failureMessage($e));
		}
		$status = $response->getStatusCode();
		if ($status === 401 || $status === 403) {
			throw new \InvalidArgumentException('Trello refused the request. Check the API key, the token, and that the token can read this board.');
		}
		if ($status === 404) {
			throw new \InvalidArgumentException('The Trello board was not found.');
		}
		if ($status !== 200) {
			throw new \InvalidArgumentException('Trello returned HTTP ' . $status . '.');
		}
		$body = $response->getBody();
		if (!is_string($body) || $body === '') {
			throw new \InvalidArgumentException('Trello returned an empty response.');
		}
		if (strlen($body) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('The Trello board is larger than 40 MB.');
		}
		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('Trello returned a response that is not valid JSON.');
		}
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('Trello returned a response that could not be read.');
		}

		return $decoded;
	}

	private function failureMessage(\Throwable $e): string {
		$message = $e->getMessage();
		if (preg_match('/\b401\b/', $message) === 1 || preg_match('/\b403\b/', $message) === 1) {
			return 'Trello refused the request. Check the API key, the token, and that the token can read this board.';
		}
		if (preg_match('/\b404\b/', $message) === 1) {
			return 'The Trello board was not found.';
		}
		return 'Could not read the Trello board.';
	}
}
