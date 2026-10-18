<?php

namespace Mtai\AiSearch\Vector;

use Bitrix\Main\Loader;
use Mtai\AiSearch\AiSearchException;
use Mtai\AiSearch\Config;
use Mtai\OpenSearch\OpenSearchClient;

/**
 * Отдельный kNN-индекс в том же OpenSearch, что и mtai.opensearch.
 * _id совпадает с документом морфологического индекса.
 */
final class IndexManager
{
	private ?OpenSearchClient $client = null;

	public function client(): OpenSearchClient
	{
		if ($this->client === null)
		{
			if (!Loader::includeModule('mtai.opensearch'))
			{
				throw new AiSearchException('Модуль mtai.opensearch не установлен.', 'DEPENDENCY');
			}
			$this->client = new OpenSearchClient();
		}

		return $this->client;
	}

	public function exists(): bool
	{
		try
		{
			return $this->client()->indexExists(Config::vectorIndex());
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function ensure(int $dimension): void
	{
		$client = $this->client();
		$name = Config::vectorIndex();
		if ($client->indexExists($name))
		{
			$this->assertDimension($dimension);

			return;
		}

		$client->createIndex($name, $this->body($dimension));
		$this->remember($dimension);
	}

	public function recreate(int $dimension): void
	{
		$client = $this->client();
		$name = Config::vectorIndex();
		if ($client->indexExists($name))
		{
			$client->deleteIndex($name);
		}

		$client->createIndex($name, $this->body($dimension));
		$this->remember($dimension);
	}

	public function count(): int
	{
		if (!$this->exists())
		{
			return 0;
		}

		try
		{
			$this->client()->refresh(Config::vectorIndex());
		}
		catch (\Throwable)
		{
		}

		return $this->client()->count(Config::vectorIndex());
	}

	private function assertDimension(int $dimension): void
	{
		$stored = Config::getInt('embed_dimension', 0);
		if ($stored > 0 && $stored !== $dimension)
		{
			throw new AiSearchException(
				'Размерность эмбеддинга изменилась (' . $stored . ' → ' . $dimension . '). Пересоздайте векторный индекс и пересчитайте векторы.',
				'DIMENSION_MISMATCH'
			);
		}
		if ($stored <= 0)
		{
			$this->remember($dimension);
		}
	}

	private function remember(int $dimension): void
	{
		Config::set('embed_dimension', (string)$dimension);
		Config::set('embed_fingerprint', (new Embedder())->fingerprint());
	}

	private function body(int $dimension): array
	{
		return [
			'settings' => [
				'index' => [
					'knn' => true,
				],
			],
			'mappings' => [
				'properties' => [
					'entity_type' => ['type' => 'keyword'],
					'iblock_id' => ['type' => 'long'],
					'category_id' => ['type' => 'long'],
					'text_hash' => ['type' => 'keyword'],
					'embedding' => [
						'type' => 'knn_vector',
						'dimension' => $dimension,
						'method' => [
							'name' => 'hnsw',
							'space_type' => 'cosinesimil',
							'engine' => 'lucene',
						],
					],
				],
			],
		];
	}
}
