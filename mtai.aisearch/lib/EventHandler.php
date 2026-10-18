<?php

namespace Mtai\AiSearch;

use Bitrix\Main\EventManager;
use Mtai\AiSearch\Search\HybridSearch;
use Mtai\AiSearch\Vector\Queue;

/** Подписка на события opensearch. Отдельный блок в подсказке не выводится. */
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
			'OnEndBufferContent',
			$module,
			self::class,
			'onEndBufferContent'
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
	 * Отдельный блок в подсказке не рисуется. Совпадения по смыслу
	 * приходят в том же ответе поиска и попадают в штатные секции
	 * (сделки, контакты, компании, лиды) и в секции opensearch
	 * (сотрудники, инфоблоки).
	 */
	public static function onEndBufferContent(&$content): void
	{
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
