<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\AppInfo\Application;
use OCP\IConfig;

class ApiKey {
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function configured(): string {
		$value = trim((string)$this->config->getAppValue(Application::APP_ID, 'api_key', ''));
		if (!preg_match('/^[A-Za-z0-9]{8,128}$/', $value)) {
			return '';
		}
		return $value;
	}

	public function resolve(string $override): string {
		$override = trim($override);
		if ($override !== '') {
			return $override;
		}
		return $this->configured();
	}
}
