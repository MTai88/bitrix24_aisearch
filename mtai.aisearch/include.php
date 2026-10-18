<?php
/**
 * Модуль mtai.aisearch — гибридный поиск (BM25 + kNN) и блок «Ответ AI»
 * в шапке портала. Зависит от mtai.opensearch и mtai.aiagent.
 */

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
	'mtai.aisearch',
	[
		'Mtai\\AiSearch\\Config' => 'lib/Config.php',
		'Mtai\\AiSearch\\Dependency' => 'lib/Dependency.php',
		'Mtai\\AiSearch\\AiSearchException' => 'lib/AiSearchException.php',
		'Mtai\\AiSearch\\EventHandler' => 'lib/EventHandler.php',
		'Mtai\\AiSearch\\Agent' => 'lib/Agent.php',
		'Mtai\\AiSearch\\Status' => 'lib/Status.php',
		'Mtai\\AiSearch\\Orm\\QueueTable' => 'lib/Orm/QueueTable.php',
		'Mtai\\AiSearch\\Vector\\Text' => 'lib/Vector/Text.php',
		'Mtai\\AiSearch\\Vector\\Embedder' => 'lib/Vector/Embedder.php',
		'Mtai\\AiSearch\\Vector\\IndexManager' => 'lib/Vector/IndexManager.php',
		'Mtai\\AiSearch\\Vector\\Queue' => 'lib/Vector/Queue.php',
		'Mtai\\AiSearch\\Search\\Rrf' => 'lib/Search/Rrf.php',
		'Mtai\\AiSearch\\Search\\HybridSearch' => 'lib/Search/HybridSearch.php',
		'Mtai\\AiSearch\\Answer\\Html' => 'lib/Answer/Html.php',
		'Mtai\\AiSearch\\Answer\\Retriever' => 'lib/Answer/Retriever.php',
		'Mtai\\AiSearch\\Answer\\PromptBuilder' => 'lib/Answer/PromptBuilder.php',
		'Mtai\\AiSearch\\Answer\\Answerer' => 'lib/Answer/Answerer.php',
		'Mtai\\AiSearch\\Controller\\Answer' => 'lib/controller/answer.php',
		'Mtai\\AiSearch\\Controller\\Maintenance' => 'lib/controller/maintenance.php',
	]
);
