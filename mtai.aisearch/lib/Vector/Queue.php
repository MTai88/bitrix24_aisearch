<?php

namespace Mtai\AiSearch\Vector;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Mtai\AiSearch\Config;
use Mtai\AiSearch\Orm\QueueTable;
use Mtai\OpenSearch\OpenSearchClient;

/**
 * Очередь эмбеддинга. События индекса только ставят задачу:
 * сохранение сущности в CRM не ждёт LLM.
 */
final class Queue
{
	public static function onAfterIndexDocument(Event $event): void
	{
		self::capture($event, 'index');
	}

	public static function onAfterDeleteDocument(Event $event): void
	{
		self::capture($event, 'delete');
	}

	private static function capture(Event $event, string $action): void
	{
		$id = (string)$event->getParameter('id');
		if ($id === '')
		{
			return;
		}

		try
		{
			(new self())->enqueue($id, $action);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aisearch queue: ' . $e->getMessage(), Config::MODULE_ID);
		}
	}

	public function enqueue(string $docId, string $action): void
	{
		$this->enqueueMany([$docId], $action);
	}

	/**
	 * @param string[] $docIds
	 */
	public function enqueueMany(array $docIds, string $action): void
	{
		$action = $action === 'delete' ? 'delete' : 'index';
		$ids = [];
		foreach ($docIds as $docId)
		{
			$docId = mb_substr(trim((string)$docId), 0, 80);
			if ($docId !== '')
			{
				$ids[$docId] = $docId;
			}
		}
		if ($ids === [])
		{
			return;
		}

		$helper = Application::getConnection()->getSqlHelper();
		foreach (array_chunk(array_values($ids), 100) as $chunk)
		{
			$values = [];
			foreach ($chunk as $docId)
			{
				$values[] = "('" . $helper->forSql($docId) . "', '" . $action . "', 0, NOW())";
			}
			Application::getConnection()->queryExecute(
				'INSERT INTO mtai_aisearch_queue (DOC_ID, ACTION, ATTEMPTS, DATE_CREATE) VALUES '
				. implode(',', $values)
				. ' ON DUPLICATE KEY UPDATE ACTION = VALUES(ACTION), ATTEMPTS = 0, ERROR = NULL, DATE_CREATE = NOW()'
			);
		}
	}

	/**
	 * Ставит в очередь все документы морфологического индекса.
	 */
	public function enqueueAll(): int
	{
		if (!Loader::includeModule('mtai.opensearch'))
		{
			return 0;
		}

		$client = new OpenSearchClient();
		$index = \Mtai\OpenSearch\IndexManager::indexName();
		if (!$client->indexExists($index))
		{
			return 0;
		}

		$total = 0;
		$searchAfter = null;
		while (true)
		{
			$body = [
				'size' => 200,
				'_source' => false,
				'query' => ['match_all' => new \stdClass()],
				'sort' => [
					['entity_id' => 'asc'],
					['entity_type' => 'asc'],
				],
			];
			if ($searchAfter !== null)
			{
				$body['search_after'] = $searchAfter;
			}

			$response = $client->search($index, $body);
			$hits = (array)($response['hits']['hits'] ?? []);
			if ($hits === [])
			{
				break;
			}

			$ids = [];
			foreach ($hits as $hit)
			{
				$ids[] = (string)($hit['_id'] ?? '');
				$searchAfter = $hit['sort'] ?? $searchAfter;
			}
			$this->enqueueMany($ids, 'index');
			$total += count($ids);
			if ($searchAfter === null)
			{
				break;
			}
		}

		return $total;
	}

	public function pendingCount(): int
	{
		return (int)QueueTable::getCount(['<ATTEMPTS' => Config::maxAttempts()]);
	}

	public function totalCount(): int
	{
		return (int)QueueTable::getCount();
	}

	/**
	 * Один батч: эмбеддинг + запись в kNN-индекс.
	 *
	 * @return array{processed: int, left: int, errors: int}
	 */
	public function processBatch(): array
	{
		$rows = QueueTable::getList([
			'filter' => ['<ATTEMPTS' => Config::maxAttempts()],
			'order' => ['ID' => 'ASC'],
			'limit' => Config::batchSize(),
		])->fetchAll();

		if ($rows === [])
		{
			return ['processed' => 0, 'left' => 0, 'errors' => 0];
		}

		$indexManager = new IndexManager();
		$embedder = new Embedder();
		try
		{
			$dimension = $embedder->dimension(Config::getInt('embed_dimension', 0) <= 0);
			$indexManager->ensure($dimension);
		}
		catch (\Throwable $e)
		{
			$this->failRows($rows, $e->getMessage());

			return ['processed' => 0, 'left' => $this->pendingCount(), 'errors' => count($rows)];
		}

		$deletes = [];
		$indexes = [];
		foreach ($rows as $row)
		{
			if (($row['ACTION'] ?? '') === 'delete')
			{
				$deletes[] = $row;
			}
			else
			{
				$indexes[] = $row;
			}
		}

		$processed = 0;
		$errors = 0;

		foreach ($deletes as $row)
		{
			try
			{
				$indexManager->client()->deleteDocument(Config::vectorIndex(), (string)$row['DOC_ID']);
				QueueTable::delete((int)$row['ID']);
				$processed++;
			}
			catch (\Throwable $e)
			{
				$this->failRow($row, $e->getMessage());
				$errors++;
			}
		}

		if ($indexes !== [])
		{
			try
			{
				$processed += $this->indexRows($indexes, $embedder, $indexManager, $dimension);
			}
			catch (\Throwable $e)
			{
				$this->failRows($indexes, $e->getMessage());
				$errors += count($indexes);
				AddMessage2Log('mtai.aisearch embed batch: ' . $e->getMessage(), Config::MODULE_ID);
			}
		}

		return ['processed' => $processed, 'left' => $this->pendingCount(), 'errors' => $errors];
	}

	/**
	 * @param array[] $rows
	 */
	private function indexRows(array $rows, Embedder $embedder, IndexManager $indexManager, int $dimension): int
	{
		$ids = array_map(static fn ($row) => (string)$row['DOC_ID'], $rows);
		$sources = $this->loadMorphoSources($ids);
		$hashes = $this->loadHashes($indexManager, $ids);

		$skip = [];
		$missing = [];
		$todo = [];
		foreach ($rows as $row)
		{
			$id = (string)$row['DOC_ID'];
			if (!isset($sources[$id]))
			{
				$missing[] = $row;
				continue;
			}
			$text = Text::fromDocument($sources[$id]);
			$hash = Text::hash($text);
			if (($hashes[$id] ?? '') === $hash)
			{
				$skip[] = $row;
				continue;
			}
			$todo[] = ['row' => $row, 'source' => $sources[$id], 'text' => $text, 'hash' => $hash];
		}

		foreach ($skip as $row)
		{
			QueueTable::delete((int)$row['ID']);
		}
		foreach ($missing as $row)
		{
			$indexManager->client()->deleteDocument(Config::vectorIndex(), (string)$row['DOC_ID']);
			QueueTable::delete((int)$row['ID']);
		}

		if ($todo === [])
		{
			return count($skip) + count($missing);
		}

		$vectors = $embedder->embed(array_map(static fn ($item) => $item['text'], $todo));
		if (count($vectors) !== count($todo))
		{
			throw new \RuntimeException('Эмбеддер вернул ' . count($vectors) . ' векторов из ' . count($todo));
		}

		$bulk = [];
		$name = Config::vectorIndex();
		foreach ($todo as $i => $item)
		{
			$vector = $vectors[$i] ?? null;
			if (!is_array($vector) || count($vector) !== $dimension)
			{
				throw new \RuntimeException('Размерность вектора не совпадает с индексом (' . $dimension . ')');
			}
			$source = $item['source'];
			$id = (string)$item['row']['DOC_ID'];
			$bulk[] = ['index' => ['_index' => $name, '_id' => $id]];
			$bulk[] = [
				'entity_type' => (string)($source['entity_type'] ?? ''),
				'iblock_id' => (int)($source['iblock_id'] ?? 0),
				'category_id' => (int)($source['category_id'] ?? 0),
				'text_hash' => $item['hash'],
				'embedding' => array_map('floatval', $vector),
			];
		}

		$indexManager->client()->bulk($bulk);
		foreach ($todo as $item)
		{
			QueueTable::delete((int)$item['row']['ID']);
		}

		return count($skip) + count($missing) + count($todo);
	}

	/**
	 * @param string[] $ids
	 * @return array<string, array>
	 */
	private function loadMorphoSources(array $ids): array
	{
		if ($ids === [] || !Loader::includeModule('mtai.opensearch'))
		{
			return [];
		}

		$client = new OpenSearchClient();
		$index = \Mtai\OpenSearch\IndexManager::indexName();
		if (!$client->indexExists($index))
		{
			return [];
		}

		$response = $client->search($index, [
			'size' => count($ids),
			'query' => ['ids' => ['values' => array_values($ids)]],
		]);

		$map = [];
		foreach ((array)($response['hits']['hits'] ?? []) as $hit)
		{
			$map[(string)($hit['_id'] ?? '')] = (array)($hit['_source'] ?? []);
		}

		return $map;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, string>
	 */
	private function loadHashes(IndexManager $indexManager, array $ids): array
	{
		if ($ids === [] || !$indexManager->exists())
		{
			return [];
		}

		try
		{
			$response = $indexManager->client()->search(Config::vectorIndex(), [
				'size' => count($ids),
				'_source' => ['text_hash'],
				'query' => ['ids' => ['values' => array_values($ids)]],
			]);
		}
		catch (\Throwable)
		{
			return [];
		}

		$map = [];
		foreach ((array)($response['hits']['hits'] ?? []) as $hit)
		{
			$map[(string)($hit['_id'] ?? '')] = (string)($hit['_source']['text_hash'] ?? '');
		}

		return $map;
	}

	/** @param array[] $rows */
	private function failRows(array $rows, string $error): void
	{
		foreach ($rows as $row)
		{
			$this->failRow($row, $error);
		}
	}

	private function failRow(array $row, string $error): void
	{
		QueueTable::update((int)$row['ID'], [
			'ATTEMPTS' => (int)$row['ATTEMPTS'] + 1,
			'ERROR' => mb_substr($error, 0, 1000),
		]);
	}
}
