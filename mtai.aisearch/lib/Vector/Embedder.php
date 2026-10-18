<?php

namespace Mtai\AiSearch\Vector;

use Bitrix\Main\Loader;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Llm\ProviderInterface;
use Mtai\AiSearch\AiSearchException;

/**
 * Эмбеддер из настроек mtai.aiagent.
 * HashEmbedder и мок сравнивают слова, а не смысл: для гибридного поиска
 * они годятся только чтобы прогнать конвейер.
 */
final class Embedder
{
	private static ?int $probedDimension = null;

	public function create(): ?ProviderInterface
	{
		if (!Loader::includeModule('mtai.aiagent'))
		{
			return null;
		}

		try
		{
			return Factory::createEmbedder();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public function isSemantic(?ProviderInterface $embedder = null): bool
	{
		$embedder ??= $this->create();
		if ($embedder === null)
		{
			return false;
		}

		return !in_array($embedder::code(), ['hash', 'mock'], true);
	}

	public function fingerprint(): string
	{
		$embedder = $this->create();
		if ($embedder === null)
		{
			return '';
		}

		$model = \Mtai\Aiagent\Config::get('llm_embedding_model', '');
		$mode = \Mtai\Aiagent\Config::get('llm_embedding_provider', 'auto');

		return $embedder::code() . '|' . $mode . '|' . $model;
	}

	public function title(): string
	{
		$embedder = $this->create();

		return $embedder !== null ? $embedder->title() : '';
	}

	/**
	 * @param string[] $texts
	 * @return float[][]
	 */
	public function embed(array $texts, ?float $timeout = null): array
	{
		$embedder = $this->create();
		if ($embedder === null || !$embedder->supportsEmbedding())
		{
			throw new AiSearchException('Эмбеддер недоступен. Проверьте настройки mtai.aiagent.', 'EMBEDDER_UNAVAILABLE');
		}

		$texts = array_values($texts);
		if ($texts === [])
		{
			return [];
		}

		if ($timeout !== null && $timeout > 0 && method_exists(\Mtai\Aiagent\Config::class, 'withHttpTimeout'))
		{
			return \Mtai\Aiagent\Config::withHttpTimeout((int)ceil($timeout), static fn () => $embedder->embed($texts));
		}

		return $embedder->embed($texts);
	}

	/**
	 * Размерность вектора. Сохранённое значение не перепроверяется,
	 * пока индекс не пересоздают ($probe = true считает заново).
	 */
	public function dimension(bool $probe = false): int
	{
		$stored = \Mtai\AiSearch\Config::getInt('embed_dimension', 0);
		if ($stored > 0 && !$probe)
		{
			return $stored;
		}
		if (self::$probedDimension !== null && !$probe)
		{
			return self::$probedDimension;
		}

		$vectors = $this->embed(['размерность']);
		$vector = $vectors[0] ?? null;
		$dimension = is_array($vector) ? count($vector) : 0;
		if ($dimension <= 0)
		{
			throw new AiSearchException('Эмбеддер вернул пустой вектор.', 'EMBEDDER_UNAVAILABLE');
		}

		self::$probedDimension = $dimension;

		return $dimension;
	}
}
