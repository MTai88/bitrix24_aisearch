<?php

namespace Mtai\AiSearch\Search;

/**
 * Reciprocal Rank Fusion: объединение двух выдач по рангам, а не по сырым score.
 * score = wBm25 / (k + rankBm25) + wKnn / (k + rankKnn). Ранги с 1.
 */
final class Rrf
{
	/**
	 * @param array[] $bm25Hits элементы с _id и _source
	 * @param array[] $knnHits элементы с _id и _score (без _source)
	 * @param array<string, array> $sourcesById _source для хитов, которых нет в BM25
	 * @return array[] хиты в формате _search: _id, _source, _score
	 */
	public static function merge(
		array $bm25Hits,
		array $knnHits,
		array $sourcesById,
		float $weightBm25,
		float $weightKnn,
		int $k,
		int $limit
	): array {
		$ranks = [];
		$rank = 1;
		foreach ($bm25Hits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id === '' || isset($ranks[$id]) || !is_array($hit['_source'] ?? null))
			{
				continue;
			}
			$ranks[$id] = ['bm25' => $rank, 'knn' => null, 'hit' => $hit];
			$rank++;
		}

		$rank = 1;
		foreach ($knnHits as $hit)
		{
			$id = (string)($hit['_id'] ?? '');
			if ($id === '')
			{
				continue;
			}
			if (!isset($ranks[$id]))
			{
				$source = $sourcesById[$id] ?? null;
				if (!is_array($source))
				{
					continue;
				}
				$ranks[$id] = [
					'bm25' => null,
					'knn' => $rank,
					'hit' => [
						'_id' => $id,
						'_source' => $source,
						'_score' => (float)($hit['_score'] ?? 0),
					],
				];
			}
			else
			{
				$ranks[$id]['knn'] = $rank;
			}
			$rank++;
		}

		$scored = [];
		foreach ($ranks as $row)
		{
			$score = 0.0;
			if ($row['bm25'] !== null)
			{
				$score += $weightBm25 / ($k + $row['bm25']);
			}
			if ($row['knn'] !== null)
			{
				$score += $weightKnn / ($k + $row['knn']);
			}
			$hit = $row['hit'];
			$hit['_score'] = $score;
			$scored[] = ['score' => $score, 'hit' => $hit];
		}

		usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
		$scored = array_slice($scored, 0, max(1, $limit));

		return array_map(static fn ($row) => $row['hit'], $scored);
	}
}
