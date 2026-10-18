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

	/** @var array<string, string> id документа kNN => bm25|lexical|outlier|dropped */
	private array $lastReasons = [];

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
				$merged = $this->fuse($query, $bm25, $knn);
				foreach ($knn as &$knnHit)
				{
					$id = (string)($knnHit['_id'] ?? '');
					$knnHit['_reason'] = $this->lastReasons[$id] ?? 'dropped';
				}
				unset($knnHit);
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

		return $this->fuse($query, $bm25Hits, $knn);
	}

	/**
	 * @param array[] $bm25Hits
	 * @param array[] $knnHits
	 * @return array[]
	 */
	private function fuse(string $query, array $bm25Hits, array $knnHits): array
	{
		$decision = $this->decide($query, $bm25Hits, $knnHits);
		$this->lastReasons = $decision['reasons'];

		$limit = 50;
		if (Loader::includeModule('mtai.opensearch'))
		{
			$limit = max(1, \Mtai\OpenSearch\Config::searchLimit() * \Mtai\OpenSearch\Config::candidateFactor());
		}

		return Rrf::merge(
			$bm25Hits,
			$decision['keep'],
			$decision['sources'],
			Config::weightBm25(),
			Config::weightKnn(),
			Config::rrfK(),
			$limit
		);
	}

	/**
	 * Хиты BM25 остаются. Сосед по вектору проходит, если во всём документе
	 * есть общее слово с запросом или его оценка заметно выше остальных.
	 *
	 * @param array[] $bm25Hits
	 * @param array[] $knnHits
	 * @return array{keep: array[], reasons: array<string, string>, sources: array<string, array>}
	 */
	private function decide(string $query, array $bm25Hits, array $knnHits): array
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

		$sources = $this->loadSources($missing);
		$lexicalIds = $this->lexicalIds($query, $missing);
		$lexical = array_fill_keys($lexicalIds ?? [], true);
		foreach ($missing as $id)
		{
			if (isset($lexical[$id]))
			{
				continue;
			}
			$source = $sources[$id] ?? null;
			if (is_array($source) && $this->sharesTerm($query, $this->documentText($source)))
			{
				$lexical[$id] = true;
			}
		}

		$candidates = [];
		foreach ($knnHits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id === '' || isset($known[$id]) || isset($lexical[$id]))
			{
				continue;
			}
			$candidates[] = $hit;
		}
		$outliers = array_fill_keys($this->outlierIds($knnHits, $candidates), true);

		$reasons = [];
		$keep = [];
		foreach ($knnHits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id === '')
			{
				continue;
			}
			if (isset($known[$id]))
			{
				$reasons[$id] = 'bm25';
				$keep[] = $hit;
			}
			elseif (isset($lexical[$id]))
			{
				$reasons[$id] = 'lexical';
				$keep[] = $hit;
			}
			elseif (isset($outliers[$id]))
			{
				$reasons[$id] = 'outlier';
				$keep[] = $hit;
			}
			else
			{
				$reasons[$id] = 'dropped';
			}
		}

		return [
			'keep' => $keep,
			'reasons' => $reasons,
			'sources' => $sources,
		];
	}

	/**
	 * Документы среди $ids, в которых морфология OpenSearch находит слово запроса.
	 * null — запрос к индексу не выполнился, тогда сравнение делается по тексту.
	 *
	 * @param string[] $ids
	 * @return string[]|null
	 */
	private function lexicalIds(string $query, array $ids): ?array
	{
		$ids = array_values(array_unique(array_filter($ids)));
		$query = trim($query);
		if ($ids === [])
		{
			return [];
		}
		if ($query === '' || !Loader::includeModule('mtai.opensearch'))
		{
			return null;
		}

		try
		{
			$response = (new OpenSearchClient())->search(\Mtai\OpenSearch\IndexManager::indexName(), [
				'size' => count($ids),
				'_source' => false,
				'query' => [
					'bool' => [
						'filter' => [['ids' => ['values' => $ids]]],
						'must' => [[
							'multi_match' => [
								'query' => $query,
								'fields' => ['title', 'subtitle', 'fields.*'],
								'type' => 'best_fields',
								'minimum_should_match' => '1',
							],
						]],
					],
				],
			]);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aisearch lexical: ' . $e->getMessage(), Config::MODULE_ID);

			return null;
		}

		$found = [];
		foreach ((array)($response['hits']['hits'] ?? []) as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id !== '')
			{
				$found[] = $id;
			}
		}

		return $found;
	}

	/**
	 * Оценки считаются по всей выдаче kNN. В подсказку из тех, кто не прошёл
	 * по слову, попадают только самые верхние, не больше knn_semantic_max.
	 *
	 * @param array[] $knnHits
	 * @param array[] $candidates
	 * @return string[]
	 */
	private function outlierIds(array $knnHits, array $candidates): array
	{
		$scores = [];
		foreach ($knnHits as $hit)
		{
			$scores[] = (float)($hit['_score'] ?? 0);
		}
		$count = count($scores);
		if ($count < 2 || $candidates === [])
		{
			return [];
		}

		$mean = array_sum($scores) / $count;
		$variance = 0.0;
		foreach ($scores as $score)
		{
			$variance += ($score - $mean) ** 2;
		}
		$stdev = sqrt($variance / $count);
		if ($stdev < 0.000001)
		{
			return [];
		}

		$threshold = max(Config::knnMinScore(), $mean + Config::knnOutlierZ() * $stdev);
		$passed = [];
		foreach ($candidates as $hit)
		{
			if ((float)($hit['_score'] ?? 0) >= $threshold)
			{
				$passed[] = $hit;
			}
		}
		usort($passed, static fn (array $left, array $right): int => ((float)$right['_score']) <=> ((float)$left['_score']));
		$passed = array_slice($passed, 0, Config::knnSemanticMax());

		$ids = [];
		foreach ($passed as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id !== '')
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	private function documentText(array $source): string
	{
		$parts = [
			trim((string)($source['title'] ?? '')),
			trim((string)($source['subtitle'] ?? '')),
		];
		$fields = $source['fields'] ?? [];
		if (is_array($fields))
		{
			foreach ($fields as $code => $value)
			{
				if (str_ends_with((string)$code, '.en'))
				{
					continue;
				}
				$flat = $this->flattenField($value);
				if ($flat !== '')
				{
					$parts[] = $flat;
				}
			}
		}

		return trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
	}

	private function flattenField($value): string
	{
		if (is_array($value))
		{
			$chunks = [];
			foreach ($value as $item)
			{
				$flat = $this->flattenField($item);
				if ($flat !== '')
				{
					$chunks[] = $flat;
				}
			}

			return implode(' ', $chunks);
		}
		if (is_bool($value) || $value === null)
		{
			return '';
		}

		return trim((string)$value);
	}

	/**
	 * Запасное сравнение, если морфологический запрос к индексу не выполнился.
	 * Смотрит весь текст документа: точное совпадение, префикс («Иван»/«Иванов»)
	 * или первые 5 букв («поставки»/«поставка»).
	 */
	private function sharesTerm(string $query, string $text): bool
	{
		$queryTokens = $this->tokens($query);
		$textTokens = $this->tokens($text);
		foreach ($queryTokens as $queryToken)
		{
			foreach ($textTokens as $textToken)
			{
				if ($this->sameStem($queryToken, $textToken))
				{
					return true;
				}
			}
		}

		return false;
	}

	/** @return string[] */
	private function tokens(string $text): array
	{
		$text = mb_strtolower($text);
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts))
		{
			return [];
		}
		$tokens = [];
		foreach ($parts as $part)
		{
			if (mb_strlen($part) >= 3)
			{
				$tokens[] = $part;
			}
		}

		return $tokens;
	}

	private function sameStem(string $left, string $right): bool
	{
		if ($left === $right)
		{
			return true;
		}
		$shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
		$longer = $shorter === $left ? $right : $left;
		if (mb_strlen($shorter) >= 4 && mb_strpos($longer, $shorter) === 0)
		{
			return true;
		}
		$prefix = 5;
		if (mb_strlen($left) >= $prefix && mb_strlen($right) >= $prefix
			&& mb_substr($left, 0, $prefix) === mb_substr($right, 0, $prefix))
		{
			return true;
		}

		return false;
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
			$row = [
				'rank' => $rank++,
				'id' => (string)($hit['_id'] ?? ''),
				'title' => (string)($source['title'] ?? ''),
				'type' => (string)($source['entity_type'] ?? ''),
				'score' => isset($hit['_score']) ? round((float)$hit['_score'], 4) : null,
			];
			if (isset($hit['_reason']))
			{
				$row['reason'] = (string)$hit['_reason'];
			}
			$rows[] = $row;
		}

		return $rows;
	}
}
