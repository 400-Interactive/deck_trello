<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\TrelloCredentials;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

class TrelloAttachmentClient {
	private const MAX_BYTES = 33554432;
	private const INITIAL_HOSTS = ['trello.com', 'api.trello.com'];

	private readonly IClient $client;

	public function __construct(IClientService $clientService) {
		$this->client = $clientService->newClient();
	}

	public static function isAllowedUrl(string $url, bool $initial): bool {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}
		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}
		if (isset($parts['port']) && (int)$parts['port'] !== 443) {
			return false;
		}
		$host = strtolower((string)($parts['host'] ?? ''));
		if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
			return false;
		}
		if (in_array($host, self::INITIAL_HOSTS, true)) {
			return true;
		}
		if ($initial) {
			return false;
		}

		return str_starts_with($host, 'trello-attachments.') && str_ends_with($host, '.amazonaws.com');
	}

	public function download(string $url, TrelloCredentials $credentials): string {
		if (!self::isAllowedUrl($url, true)) {
			throw new \RuntimeException('This attachment is not a Trello file.');
		}
		$current = self::apiDownloadUrl($url);
		for ($hop = 0; $hop < 4; $hop++) {
			$headers = [
				'User-Agent' => 'Nextcloud Deck Trello Import',
			];
			if (self::needsApiAuthorization($current)) {
				$headers['Authorization'] = 'OAuth oauth_consumer_key="' . $credentials->key . '", oauth_token="' . $credentials->token . '"';
			}
			try {
				$response = $this->client->get($current, [
					'timeout' => 45,
					'allow_redirects' => false,
					'headers' => $headers,
				]);
			} catch (\Throwable $e) {
				throw new \RuntimeException($this->failureMessage($e));
			}

			$status = $response->getStatusCode();
			if ($status >= 300 && $status < 400) {
				$location = $response->getHeader('Location');
				if (!is_string($location) || trim($location) === '') {
					throw new \RuntimeException('Trello redirected the download without a destination.');
				}
				$current = $this->resolve($current, trim($location));
				if (!self::isAllowedUrl($current, false)) {
					throw new \RuntimeException('Trello redirected the file to a host that is not allowed.');
				}
				continue;
			}
			if ($status === 401 || $status === 403) {
				throw new \RuntimeException('Trello refused the download. Check the API key and token.');
			}
			if ($status !== 200) {
				throw new \RuntimeException('Trello returned HTTP ' . $status . '.');
			}
			$body = $response->getBody();
			if (!is_string($body) || $body === '') {
				throw new \RuntimeException('Trello returned an empty file.');
			}
			if (strlen($body) > self::MAX_BYTES) {
				throw new \RuntimeException('The file is larger than 32 MB.');
			}
			return $body;
		}

		throw new \RuntimeException('Trello redirected the download too many times.');
	}

	public static function apiDownloadUrl(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return $url;
		}
		$host = strtolower((string)($parts['host'] ?? ''));
		$path = (string)($parts['path'] ?? '/');
		if ($host !== 'trello.com' || !str_starts_with($path, '/1/')) {
			return $url;
		}
		$parts['host'] = 'api.trello.com';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';

		return 'https://api.trello.com' . $path . $query;
	}

	private static function needsApiAuthorization(string $url): bool {
		$host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

		return $host === 'api.trello.com' || $host === 'trello.com';
	}

	private function resolve(string $base, string $location): string {
		if (str_starts_with($location, 'https://')) {
			return $location;
		}
		if (str_starts_with($location, 'http://') || str_starts_with($location, '//')) {
			throw new \RuntimeException('Trello redirected the file to a host that is not allowed.');
		}
		$parts = parse_url($base);
		$host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
		if ($host === '') {
			throw new \RuntimeException('Trello redirected the file to a host that is not allowed.');
		}
		if (str_starts_with($location, '/')) {
			return 'https://' . $host . $location;
		}
		$path = is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
		$dir = substr($path, 0, (int)strrpos($path, '/') + 1);

		return 'https://' . $host . $dir . $location;
	}

	private function failureMessage(\Throwable $e): string {
		$message = $e->getMessage();
		if (preg_match('/\b(401|403)\b/', $message) === 1) {
			return 'Trello refused the download. Check the API key and token.';
		}
		return 'Could not download the Trello file.';
	}
}
