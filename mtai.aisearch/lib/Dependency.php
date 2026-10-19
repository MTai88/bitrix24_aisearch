<?php

namespace Mtai\AiSearch;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Mtai\AiSearch\Vector\Embedder;

/** Проверка модулей, без которых гибридный поиск не работает. */
final class Dependency
{
	public static function opensearchInstalled(): bool
	{
		return ModuleManager::isModuleInstalled('mtai.opensearch');
	}

	public static function aiagentInstalled(): bool
	{
		return ModuleManager::isModuleInstalled('mtai.aiagent');
	}

	public static function opensearchReachable(): bool
	{
		if (!self::opensearchInstalled() || !Loader::includeModule('mtai.opensearch'))
		{
			return false;
		}

		try
		{
			return (new \Mtai\OpenSearch\OpenSearchClient())->ping();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/** @return string[] */
	public static function problems(): array
	{
		$problems = [];
		if (!self::opensearchInstalled())
		{
			$problems[] = 'Не установлен модуль mtai.opensearch (нужна версия с событиями onSearchHits и onAfterIndexDocument, 1.3.0+).';
		}
		elseif (!self::opensearchReachable())
		{
			$problems[] = 'OpenSearch недоступен по настройкам mtai.opensearch. Поиск откатится на штатный.';
		}

		if (!self::aiagentInstalled())
		{
			$problems[] = 'Не установлен модуль mtai.aiagent.';
		}
		elseif (Loader::includeModule('mtai.aiagent'))
		{
			$embedder = new Embedder();
			if ($embedder->create() === null)
			{
				$problems[] = 'Эмбеддер mtai.aiagent выключен или не настроен (опция llm_embedding_provider).';
			}
			elseif (!$embedder->isSemantic())
			{
				$problems[] = 'Сейчас эмбеддер лексический (мок или встроенный хэш). Конвейер работает, но поиск по смыслу появится только с эмбеддингами провайдера (OpenAI, YandexGPT, GigaChat).';
			}
		}

		$stored = Config::get('embed_fingerprint', '');
		$current = (new Embedder())->fingerprint();
		if ($stored !== '' && $current !== '' && $stored !== $current)
		{
			$problems[] = 'Провайдер эмбеддингов сменился. Пересоздайте векторный индекс и пересчитайте векторы.';
		}

		return $problems;
	}
}
