<?php

namespace Mtai\AiSearch\Search;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Mtai\AiSearch\Config;
use Mtai\AiSearch\Vector\Embedder;
use Mtai\AiSearch\Vector\IndexManager;
use Mtai\OpenSearch\OpenSearchClient;

/**
 * Подмена выдачи BM25 гибридом BM25 + kNN.
 * Любой сбой оставляет исходные хиты: поиск в шапке не должен падать.
 */
final class HybridSearch
{
	private static bool $capture = false;

	/** @var array<string, mixed>|null */
	private static ?array $lastContext = null;

	public static function onSearchHits(Event $event): ?EventResult
	{
		if (self::$capture)
		{
			self::$lastContext = $event->getParameters();
		}

		try
		{
			if (!Config::hybridEnabled())
			{
				return null;
			}

			$query = trim((string)$event->getParameter('query'));
			if (mb_strlen($query) < Config::hybridMinLength())
			{
				return null;
			}
			if (Config::getInt('embed_dimension', 0) <= 0 || !(new IndexManager())->exists())
			{
				return null;
			}

			$merged = (new self())->mergeHits(
				$query,
				(array)$event->getParameter('entityTypes'),
				(array)$event->getParameter('iblockIds'),
				$event->getParameter('categoryId') !== null ? (int)$event->getParameter('categoryId') : null,
				(array)$event->getParameter('hits')
			);
			if ($merged === null)
			{
				return null;
			}

			return new EventResult(EventResult::SUCCESS, ['hits' => $merged]);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aisearch hybrid: ' . $e->getMessage(), Config::MODULE_ID);

			return null;
		}
	}

	/**
	 * Диагностика для настроек: ранги BM25, kNN и итог.
	 * Права применяются к полю visible, ранги считаются до фильтра прав.
	 *
	 * @return array<string, mixed>
	 */
	public function explain(string $query, int $userId): array
	{
		self::$capture = true;
		self::$lastContext = null;
		$error = null;
		$items = [];

		try
		{
			if (!Loader::includeModule('mtai.opensearch'))
			{
				throw new \RuntimeException('Модуль mtai.opensearch не установлен');
			}
			$items = (new \Mtai\OpenSearch\SearchService())->search($query, ['user_id' => $userId]);
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}
		finally
		{
			self::$capture = false;
		}

		$context = self::$lastContext ?? [];
		$bm25 = array_values((array)($context['hits'] ?? []));
		$entityTypes = array_values((array)($context['entityTypes'] ?? []));
		$iblockIds = array_values((array)($context['iblockIds'] ?? []));
		$categoryId = isset($context['categoryId']) && $context['categoryId'] !== null
			? (int)$context['categoryId']
			: null;

		$knn = [];
		$merged = $bm25;
		try
		{
			$vector = $this->embedQuery($query);
			if ($vector === null)
			{
				$error ??= 'Эмбеддинг запроса недоступен: таймаут, эмбеддер или несовпадение провайдера с индексом';
			}
			else
			{
				$knn = $this->knn($vector, $entityTypes, $iblockIds, $categoryId);
				$merged = $this->fuse($bm25, $knn);
			}
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}

		$visible = [];
		foreach ($items as $item)
		{
			$visible[] = [
				'title' => (string)$item->getTitle(),
				'type' => (string)$item->getType(),
			];
		}

		return [
			'error' => $error,
			'bm25' => $this->brief($bm25),
			'knn' => $this->brief($this->withSources($knn)),
			'merged' => $this->brief($merged),
			'visible' => $visible,
		];
	}

	/**
	 * @param string[] $entityTypes
	 * @param int[] $iblockIds
	 * @param array[] $bm25Hits
	 * @return array[]|null null — оставить исходную выдачу
	 */
	private function mergeHits(string $query, array $entityTypes, array $iblockIds, ?int $categoryId, array $bm25Hits): ?array
	{
		$vector = $this->embedQuery($query);
		if ($vector === null)
		{
			return null;
		}

		$knn = $this->knn($vector, $entityTypes, $iblockIds, $categoryId);

		return $this->fuse($bm25Hits, $knn);
	}

	/**
	 * @param array[] $bm25Hits
	 * @param array[] $knnHits
	 * @return array[]
	 */
	private function fuse(array $bm25Hits, array $knnHits): array
	{
		$known = [];
		foreach ($bm25Hits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id !== '')
			{
				$known[$id] = true;
			}
		}

		$missing = [];
		foreach ($knnHits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id !== '' && !isset($known[$id]))
			{
				$missing[] = $id;
			}
		}

		$limit = 50;
		if (Loader::includeModule('mtai.opensearch'))
		{
			$limit = max(1, \Mtai\OpenSearch\Config::searchLimit() * \Mtai\OpenSearch\Config::candidateFactor());
		}

		return Rrf::merge(
			$bm25Hits,
			$knnHits,
			$this->loadSources($missing),
			Config::weightBm25(),
			Config::weightKnn(),
			Config::rrfK(),
			$limit
		);
	}

	/** @return float[]|null */
	private function embedQuery(string $query): ?array
	{
		$query = trim($query);
		if ($query === '')
		{
			return null;
		}

		$embedder = new Embedder();
		$fingerprint = $embedder->fingerprint();
		$stored = Config::get('embed_fingerprint', '');
		if ($stored !== '' && $fingerprint !== '' && $stored !== $fingerprint)
		{
			return null;
		}

		$ttl = Config::queryCacheTtl();
		$cache = Cache::createInstance();
		$key = md5($fingerprint . '|' . mb_strtolower($query));
		$dir = 'mtai.aisearch/query';
		if ($ttl > 0 && $cache->initCache($ttl, $key, $dir))
		{
			$vars = $cache->getVars();
			if (is_array($vars['v'] ?? null) && $vars['v'] !== [])
			{
				return $vars['v'];
			}
		}

		try
		{
			$vectors = $embedder->embed([$query], Config::embedTimeout());
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aisearch embed query: ' . $e->getMessage(), Config::MODULE_ID);

			return null;
		}

		$vector = $vectors[0] ?? null;
		if (!is_array($vector) || $vector === [])
		{
			return null;
		}

		if ($ttl > 0 && $cache->startDataCache($ttl, $key, $dir))
		{
			$cache->endDataCache(['v' => $vector]);
		}

		return $vector;
	}

	/**
	 * @param float[] $vector
	 * @param string[] $entityTypes
	 * @param int[] $iblockIds
	 * @return array[]
	 */
	private function knn(array $vector, array $entityTypes, array $iblockIds, ?int $categoryId): array
	{
		if ($entityTypes === [])
		{
			return [];
		}

		$indexManager = new IndexManager();
		if (!$indexManager->exists())
		{
			return [];
		}

		$filter = [
			['terms' => ['entity_type' => array_values($entityTypes)]],
		];
		if ($iblockIds !== [])
		{
			$filter[] = [
				'bool' => [
					'should' => [
						['bool' => ['must_not' => [['terms' => ['entity_type' => ['iblock', 'iblock_section']]]]]],
						['terms' => ['iblock_id' => array_values(array_map('intval', $iblockIds))]],
					],
					'minimum_should_match' => 1,
				],
			];
		}
		if ($categoryId !== null)
		{
			$filter[] = ['term' => ['category_id' => $categoryId]];
		}

		$k = Config::knnSize();
		$body = [
			'size' => $k,
			'_source' => false,
			'query' => [
				'knn' => [
					'embedding' => [
						'vector' => array_map('floatval', $vector),
						'k' => $k,
						'filter' => ['bool' => ['filter' => $filter]],
					],
				],
			],
		];
		$minScore = Config::knnMinScore();
		if ($minScore > 0)
		{
			$body['min_score'] = $minScore;
		}

		$response = $indexManager->client()->search(Config::vectorIndex(), $body);
		$hits = [];
		foreach ((array)($response['hits']['hits'] ?? []) as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id === '')
			{
				continue;
			}
			$hits[] = [
				'_id' => $id,
				'_score' => (float)($hit['_score'] ?? 0),
			];
		}

		return $hits;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, array>
	 */
	private function loadSources(array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids)));
		if ($ids === [] || !Loader::includeModule('mtai.opensearch'))
		{
			return [];
		}

		$client = new OpenSearchClient();
		$index = \Mtai\OpenSearch\IndexManager::indexName();
		$response = $client->search($index, [
			'size' => count($ids),
			'query' => ['ids' => ['values' => $ids]],
		]);

		$map = [];
		foreach ((array)($response['hits']['hits'] ?? []) as $hit)
		{
			$map[(string)($hit['_id'] ?? '')] = (array)($hit['_source'] ?? []);
		}

		return $map;
	}

	/**
	 * @param array[] $knnHits
	 * @return array[]
	 */
	private function withSources(array $knnHits): array
	{
		$ids = array_map(static fn ($hit) => (string)($hit['_id'] ?? ''), $knnHits);
		$sources = $this->loadSources($ids);
		foreach ($knnHits as &$hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if (isset($sources[$id]))
			{
				$hit['_source'] = $sources[$id];
			}
		}

		return $knnHits;
	}

	/**
	 * @param array[] $hits
	 * @return array<int, array{rank: int, id: string, title: string, type: string, score: ?float}>
	 */
	private function brief(array $hits): array
	{
		$rows = [];
		$rank = 1;
		foreach ($hits as $hit)
		{
			$source = (array)($hit['_source'] ?? []);
			$rows[] = [
				'rank' => $rank++,
				'id' => (string)($hit['_id'] ?? ''),
				'title' => (string)($source['title'] ?? ''),
				'type' => (string)($source['entity_type'] ?? ''),
				'score' => isset($hit['_score']) ? round((float)$hit['_score'], 4) : null,
			];
		}

		return $rows;
	}
}
