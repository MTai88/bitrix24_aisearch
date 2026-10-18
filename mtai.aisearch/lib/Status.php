<?php

namespace Mtai\AiSearch;

use Bitrix\Main\Loader;
use Mtai\AiSearch\Vector\Embedder;
use Mtai\AiSearch\Vector\IndexManager;
use Mtai\AiSearch\Vector\Queue;

/** Сводка для страницы настроек и обслуживания. */
final class Status
{
	public static function collect(): array
	{
		$embedder = new Embedder();
		$index = new IndexManager();
		$queue = new Queue();

		$morphoTotal = null;
		if (Loader::includeModule('mtai.opensearch'))
		{
			try
			{
				$morphoTotal = (new \Mtai\OpenSearch\IndexManager())->status()['total'] ?? null;
			}
			catch (\Throwable)
			{
				$morphoTotal = null;
			}
		}

		$vectorTotal = null;
		$vectorError = null;
		try
		{
			$vectorTotal = $index->exists() ? $index->count() : 0;
		}
		catch (\Throwable $e)
		{
			$vectorError = $e->getMessage();
		}

		return [
			'hybrid_enabled' => Config::hybridEnabled(),
			'answer_enabled' => Config::answerEnabled(),
			'problems' => Dependency::problems(),
			'embedder' => $embedder->title(),
			'semantic' => $embedder->isSemantic(),
			'fingerprint' => $embedder->fingerprint(),
			'stored_fingerprint' => Config::get('embed_fingerprint', ''),
			'dimension' => Config::getInt('embed_dimension', 0),
			'vector_index' => Config::vectorIndex(),
			'vector_total' => $vectorTotal,
			'vector_error' => $vectorError,
			'morpho_total' => $morphoTotal,
			'queue_pending' => self::safeCount(static fn () => $queue->pendingCount()),
			'queue_total' => self::safeCount(static fn () => $queue->totalCount()),
		];
	}

	private static function safeCount(callable $fn): ?int
	{
		try
		{
			return (int)$fn();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
