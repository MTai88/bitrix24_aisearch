<?php

namespace Mtai\AiSearch;

use Bitrix\Main\Config\Option;

/**
 * Типизированный доступ к опциям. Значения из БД перекрывают default_option.php.
 */
final class Config
{
	public const MODULE_ID = 'mtai.aisearch';

	private static ?array $options = null;

	private static function load(): array
	{
		if (self::$options === null)
		{
			$defaults = [];
			$defaultFile = dirname(__DIR__) . '/default_option.php';
			if (is_file($defaultFile))
			{
				$defaults = (array)(include $defaultFile);
			}

			$stored = [];
			try
			{
				$stored = Option::getForModule(self::MODULE_ID);
			}
			catch (\Throwable)
			{
			}

			self::$options = array_merge($defaults, $stored);
		}

		return self::$options;
	}

	public static function reset(): void
	{
		self::$options = null;
	}

	public static function get(string $name, string $default = ''): string
	{
		$options = self::load();

		return (string)($options[$name] ?? $default);
	}

	public static function getInt(string $name, int $default = 0): int
	{
		$value = self::get($name);
		if ($value === '' || !is_numeric($value))
		{
			return $default;
		}

		return (int)$value;
	}

	public static function getFloat(string $name, float $default = 0.0): float
	{
		$value = str_replace(',', '.', self::get($name));
		if ($value === '' || !is_numeric($value))
		{
			return $default;
		}

		return (float)$value;
	}

	public static function getBool(string $name): bool
	{
		return strtoupper(self::get($name)) === 'Y';
	}

	public static function set(string $name, string $value): void
	{
		Option::set(self::MODULE_ID, $name, $value);
		self::$options = null;
	}

	public static function hybridEnabled(): bool
	{
		return self::getBool('hybrid_enabled');
	}

	public static function answerEnabled(): bool
	{
		return self::getBool('answer_enabled');
	}

	public static function vectorIndex(): string
	{
		$name = self::get('vector_index', 'bitrix24_morpho_vectors');

		return $name !== '' ? $name : 'bitrix24_morpho_vectors';
	}

	public static function rrfK(): int
	{
		return max(1, self::getInt('rrf_k', 60));
	}

	public static function weightBm25(): float
	{
		return max(0.0, self::getFloat('weight_bm25', 1.0));
	}

	public static function weightKnn(): float
	{
		return max(0.0, self::getFloat('weight_knn', 1.0));
	}

	public static function knnSize(): int
	{
		return max(1, self::getInt('knn_size', 20));
	}

	public static function knnMinScore(): float
	{
		return max(0.0, self::getFloat('knn_min_score', 0.35));
	}

	public static function knnOutlierZ(): float
	{
		return max(0.0, self::getFloat('knn_outlier_z', 3.5));
	}

	public static function knnSemanticMax(): int
	{
		return max(0, self::getInt('knn_semantic_max', 3));
	}

	public static function hybridMinLength(): int
	{
		return max(1, self::getInt('hybrid_min_length', 3));
	}

	public static function embedTimeout(): float
	{
		return max(0.2, self::getFloat('embed_timeout', 1.5));
	}

	public static function docTextLimit(): int
	{
		return max(200, self::getInt('doc_text_limit', 2000));
	}

	public static function batchSize(): int
	{
		return max(1, self::getInt('batch_size', 16));
	}

	public static function maxAttempts(): int
	{
		return max(1, self::getInt('max_attempts', 5));
	}

	public static function queryCacheTtl(): int
	{
		return max(0, self::getInt('query_cache_ttl', 3600));
	}

	public static function answerMode(): string
	{
		return 'auto';
	}

	public static function answerMinLength(): int
	{
		return max(1, self::getInt('answer_min_length', 3));
	}

	public static function answerDebounce(): int
	{
		return max(200, self::getInt('answer_debounce', 800));
	}

	public static function answerSearchSources(): int
	{
		return max(1, self::getInt('answer_search_sources', 5));
	}

	public static function answerKbSources(): int
	{
		return max(0, self::getInt('answer_kb_sources', 3));
	}

	/** @return string[] public|internal */
	public static function answerKbScopes(): array
	{
		$raw = self::get('answer_kb_scopes', 'public,internal');
		$scopes = array_values(array_filter(array_map('trim', explode(',', $raw))));

		return $scopes !== [] ? $scopes : ['public', 'internal'];
	}

	public static function answerContextChars(): int
	{
		return max(500, self::getInt('answer_context_chars', 6000));
	}

	public static function answerSourceChars(): int
	{
		return max(100, self::getInt('answer_source_chars', 1200));
	}

	public static function answerSystemPrompt(): string
	{
		return self::get('answer_system_prompt', '');
	}

	public static function answerModel(): string
	{
		return trim(self::get('answer_model', ''));
	}

	public static function answerCacheTtl(): int
	{
		return max(0, self::getInt('answer_cache_ttl', 300));
	}

	public static function answerRateLimit(): int
	{
		return max(0, self::getInt('answer_rate_limit', 10));
	}

	/** @return int[] пустой список — все авторизованные */
	public static function answerGroups(): array
	{
		$raw = self::get('answer_groups', '');
		$ids = [];
		foreach (explode(',', $raw) as $part)
		{
			$id = (int)trim($part);
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}
}
