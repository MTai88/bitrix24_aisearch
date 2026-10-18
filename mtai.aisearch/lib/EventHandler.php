<?php

namespace Mtai\AiSearch;

use Bitrix\Main\EventManager;
use Bitrix\Main\Web\Json;
use Mtai\AiSearch\Answer\Answerer;
use Mtai\AiSearch\Search\HybridSearch;
use Mtai\AiSearch\Vector\Queue;

/** Подписка на события opensearch и вывод блока «Ответ AI» в шапке. */
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

	public static function onEndBufferContent(&$content): void
	{
		try
		{
			if (!Config::answerEnabled())
			{
				return;
			}

			$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
			if ($request->get('ajax_call') === 'y')
			{
				return;
			}

			global $USER;
			if (!isset($USER) || !($USER instanceof \CUser) || !$USER->IsAuthorized())
			{
				return;
			}
			if (!(new Answerer())->allowed((int)$USER->GetID()))
			{
				return;
			}

			$pos = mb_strripos($content, '</body>');
			if ($pos === false)
			{
				return;
			}

			$config = Json::encode([
				'mode' => Config::answerMode(),
				'minLength' => Config::answerMinLength(),
				'debounce' => Config::answerDebounce(),
			], JSON_UNESCAPED_UNICODE);

			$assets = '<link rel="stylesheet" href="/bitrix/js/mtai.aisearch/aisearch.css">'
				. '<script>window.MtaiAiSearchConfig=' . $config . ';</script>'
				. '<script src="/bitrix/js/mtai.aisearch/aisearch.js" defer></script>';

			$content = mb_substr($content, 0, $pos) . $assets . mb_substr($content, $pos);
		}
		catch (\Throwable)
		{
		}
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
