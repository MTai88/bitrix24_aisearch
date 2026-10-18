<?php

/**
 * Ajax-контроллеры: /bitrix/services/main/ajax.php?action=mtai:aisearch.api.<класс>.<метод>
 */
return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Mtai\\AiSearch\\Controller',
			'namespaces' => [
				'\\Mtai\\AiSearch\\Controller' => 'api',
			],
		],
		'readonly' => true,
	],
];
