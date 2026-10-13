<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'import#preview', 'url' => '/preview', 'verb' => 'POST'],
		['name' => 'import#import', 'url' => '/import', 'verb' => 'POST'],
	],
];
