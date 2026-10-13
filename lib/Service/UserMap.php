<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

class UserMap {
	/**
	 * @param list<string> $lines
	 * @return array<string, string> lowercase Trello username => Nextcloud uid
	 */
	public static function fromLines(array $lines): array {
		$map = [];
		foreach ($lines as $line) {
			$parsed = self::parseLine($line);
			if ($parsed === null) {
				continue;
			}
			$map[$parsed[0]] = $parsed[1];
		}
		return $map;
	}

	/**
	 * @return array<string, string>
	 */
	public static function fromText(string $text): array {
		return self::fromLines(preg_split('/\R/', $text) ?: []);
	}

	/**
	 * @return array<string, string>
	 */
	public static function fromFile(string $path): array {
		if (!is_file($path) || !is_readable($path)) {
			throw new \InvalidArgumentException('The user map file cannot be read.');
		}
		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new \InvalidArgumentException('The user map file cannot be read.');
		}
		return self::fromText($contents);
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function parseLine(string $line): ?array {
		$line = trim($line);
		if ($line === '' || str_starts_with($line, '#')) {
			return null;
		}
		$parts = explode('=', $line, 2);
		if (count($parts) !== 2) {
			throw new \InvalidArgumentException('Invalid user mapping "' . $line . '". Expected trelloUsername=nextcloudUid.');
		}
		$trello = mb_strtolower(trim($parts[0]));
		$nextcloud = trim($parts[1]);
		if ($trello === '' || $nextcloud === '') {
			throw new \InvalidArgumentException('Invalid user mapping "' . $line . '". Expected trelloUsername=nextcloudUid.');
		}
		return [$trello, $nextcloud];
	}
}
