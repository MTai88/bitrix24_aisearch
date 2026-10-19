<?php

namespace Mtai\AiSearch;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Mtai\AiSearch\Search\HybridSearch;
use Mtai\AiSearch\Vector\Queue;

/** Подписка на события opensearch. */
final class EventHandler
{
	public static function register(): void
	{
		$manager = EventManager::getInstance();
		$module = Config::MODULE_ID;

		$manager->registerEventHandler(
			'mtai.opensearch',
			'onSearchHits',
			$module,
			HybridSearch::class,
			'onSearchHits'
		);
		$manager->registerEventHandler(
			'mtai.opensearch',
			'onAfterIndexDocument',
			$module,
			Queue::class,
			'onAfterIndexDocument'
		);
		$manager->registerEventHandler(
			'mtai.opensearch',
			'onAfterDeleteDocument',
			$module,
			Queue::class,
			'onAfterDeleteDocument'
		);
		$manager->registerEventHandlerCompatible(
			'main',
			'OnBuildGlobalMenu',
			$module,
			self::class,
			'onBuildGlobalMenu'
		);

		\CAgent::AddAgent(
			'\\Mtai\\AiSearch\\Agent::processQueue();',
			$module,
			'N',
			60
		);
	}

	public static function unregister(): void
	{
		$manager = EventManager::getInstance();
		$module = Config::MODULE_ID;

		$manager->unRegisterEventHandler(
			'mtai.opensearch',
			'onSearchHits',
			$module,
			HybridSearch::class,
			'onSearchHits'
		);
		$manager->unRegisterEventHandler(
			'mtai.opensearch',
			'onAfterIndexDocument',
			$module,
			Queue::class,
			'onAfterIndexDocument'
		);
		$manager->unRegisterEventHandler(
			'mtai.opensearch',
			'onAfterDeleteDocument',
			$module,
			Queue::class,
			'onAfterDeleteDocument'
		);
		$manager->unRegisterEventHandler(
			'main',
			'OnEndBufferContent',
			$module,
			self::class,
			'onEndBufferContent'
		);
		$manager->unRegisterEventHandler(
			'main',
			'OnBuildGlobalMenu',
			$module,
			self::class,
			'onBuildGlobalMenu'
		);

		\CAgent::RemoveModuleAgents($module);
	}

	/**
	 * Снимает обработчик и опции удалённого блока «Ответ AI».
	 * Вызывается один раз из include.php.
	 */
	public static function retireAnswer(): void
	{
		$module = Config::MODULE_ID;
		EventManager::getInstance()->unRegisterEventHandler(
			'main',
			'OnEndBufferContent',
			$module,
			self::class,
			'onEndBufferContent'
		);

		foreach ([
			'answer_enabled',
			'answer_mode',
			'answer_min_length',
			'answer_debounce',
			'answer_search_sources',
			'answer_kb_sources',
			'answer_kb_scopes',
			'answer_context_chars',
			'answer_source_chars',
			'answer_system_prompt',
			'answer_model',
			'answer_cache_ttl',
			'answer_rate_limit',
			'answer_groups',
		] as $name)
		{
			Option::delete($module, ['name' => $name]);
		}

		Option::set($module, 'answer_retired', 'Y');
		Config::reset();
	}

	public static function onBuildGlobalMenu(&$aGlobalMenu, &$aModuleMenu): void
	{
		$aModuleMenu[] = [
			'parent_menu' => 'global_menu_settings',
			'sort' => 3510,
			'url' => 'mtai_aisearch.php?lang=' . LANGUAGE_ID,
			'text' => 'AI-поиск: векторы',
			'title' => 'Пересчёт векторов гибридного поиска',
			'icon' => 'sys_menu_icon',
			'page_icon' => 'sys_page_icon',
		];
	}
}
