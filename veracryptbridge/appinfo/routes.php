<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'volume#mount', 'url' => '/mount', 'verb' => 'POST'],
		['name' => 'volume#unmount', 'url' => '/unmount', 'verb' => 'POST'],
		['name' => 'volume#add', 'url' => '/volumes/add', 'verb' => 'POST'],
		['name' => 'volume#remove', 'url' => '/volumes/remove', 'verb' => 'POST'],
		['name' => 'volume#clearErrors', 'url' => '/errors/clear', 'verb' => 'POST'],
	],
];
