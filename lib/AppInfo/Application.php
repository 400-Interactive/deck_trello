<?php

declare(strict_types=1);

namespace OCA\DeckTrello\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'deck_trello';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IRequest $request): void {
			try {
				$path = $request->getPathInfo() ?? '';
			} catch (\Throwable) {
				return;
			}
			if (!str_contains($path, '/apps/deck')) {
				return;
			}
			Util::addScript(self::APP_ID, 'deck-nav');
		});
	}
}
