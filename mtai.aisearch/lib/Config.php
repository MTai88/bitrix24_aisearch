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
}
