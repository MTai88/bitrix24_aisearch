<?php

namespace Mtai\AiSearch\Answer;

use Bitrix\Main\Loader;
use Bitrix\Main\Search\ResultItem;
use Mtai\AiSearch\Config;
use Mtai\AiSearch\Vector\Text;
use Mtai\OpenSearch\OpenSearchClient;

/**
 * Источники для ответа: гибридная выдача (уже с правами) и база знаний aiagent.
 *
 * @phpstan-type Source array{n: int, title: string, url: string, type: string, text: string}
 */
final class Retriever
{
	/**
	 * @return array<int, array{n: int, title: string, url: string, type: string, text: string}>
	 */
	public function retrieve(string $query, int $userId): array
	{
		$sources = [];

		if (Loader::includeModule('mtai.opensearch'))
		{
			$items = (new \Mtai\OpenSearch\SearchService())->search($query, ['user_id' => $userId]);
			$items = array_slice($items, 0, Config::answerSearchSources());
			$ids = [];
			$meta = [];
			foreach ($items as $item)
			{
				$docId = $this->documentId($item);
				if ($docId === null)
				{
					continue;
				}
				$ids[] = $docId;
				$meta[$docId] = $item;
			}

			$texts = $this->loadTexts($ids);
			foreach ($ids as $docId)
			{
				$item = $meta[$docId];
				$sources[] = [
					'title' => (string)$item->getTitle(),
					'url' => $this->url($item),
					'type' => (string)$item->getType(),
					'text' => $texts[$docId] ?? (string)$item->getTitle(),
				];
			}
		}

		if (Config::answerKbSources() > 0 && Loader::includeModule('mtai.aiagent'))
		{
			$chunks = (new \Mtai\Aiagent\Rag\VectorIndex())->search(
				$query,
				Config::answerKbSources(),
				null,
				Config::answerKbScopes()
			);
			$names = $this->documentNames(array_map(static fn ($chunk) => (int)$chunk['documentId'], $chunks));
			foreach ($chunks as $chunk)
			{
				$documentId = (int)$chunk['documentId'];
				$name = $names[$documentId] ?? ('#' . $documentId);
				$sources[] = [
					'title' => 'База знаний: ' . $name,
					'url' => '',
					'type' => 'kb',
					'text' => (string)$chunk['content'],
				];
			}
		}

		$limit = Config::answerSourceChars();
		$n = 1;
		foreach ($sources as &$source)
		{
			$source['n'] = $n++;
			if (mb_strlen($source['text']) > $limit)
			{
				$source['text'] = mb_substr($source['text'], 0, $limit);
			}
		}
		unset($source);

		return $sources;
	}

	private function documentId(ResultItem $item): ?string
	{
		$type = (string)$item->getType();
		$id = (int)$item->getId();
		if ($id <= 0 || $type === '')
		{
			return null;
		}
		if ($type === 'USER')
		{
			return 'user_' . $id;
		}
		if ($type === 'IBLOCK_ELEMENT')
		{
			return 'iblock_' . $id;
		}
		if ($type === 'IBLOCK_SECTION')
		{
			return 'iblock_section_' . $id;
		}
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$typeId = 0;
		if (preg_match('/^DYNAMIC_(\d+)$/', $type, $match))
		{
			$typeId = (int)$match[1];
		}
		else
		{
			$typeId = (int)\CCrmOwnerType::ResolveID($type);
		}
		if ($typeId <= 0)
		{
			return null;
		}

		return \Mtai\OpenSearch\DocumentBuilder::crmEntityTypeCode($typeId) . '_' . $id;
	}

	private function url(ResultItem $item): string
	{
		$link = $item->getShowLink();
		if ($link instanceof \Bitrix\Main\Web\Uri)
		{
			return $link->getUri();
		}

		return (string)$link;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, string>
	 */
	private function loadTexts(array $ids): array
	{
		if ($ids === [] || !Loader::includeModule('mtai.opensearch'))
		{
			return [];
		}

		try
		{
			$response = (new OpenSearchClient())->search(\Mtai\OpenSearch\IndexManager::indexName(), [
				'size' => count($ids),
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
			$id = (string)($hit['_id'] ?? '');
			$map[$id] = Text::fromDocument((array)($hit['_source'] ?? []));
		}

		return $map;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function documentNames(array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids)));
		if ($ids === [])
		{
			return [];
		}

		$names = [];
		$rows = \Mtai\Aiagent\Orm\DocumentTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['@ID' => $ids],
		]);
		foreach ($rows as $row)
		{
			$names[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $names;
	}
}
