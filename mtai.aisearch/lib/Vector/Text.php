<?php

namespace Mtai\AiSearch\Vector;

use Mtai\AiSearch\Config;

/**
 * Текст документа индекса mtai.opensearch, из которого считается эмбеддинг.
 * Поля — те же, что модуль поиска уже положил в документ.
 */
final class Text
{
	public static function fromDocument(array $document, ?int $limit = null): string
	{
		$limit ??= Config::docTextLimit();
		$parts = [];

		$title = trim((string)($document['title'] ?? ''));
		$subtitle = trim((string)($document['subtitle'] ?? ''));
		if ($title !== '')
		{
			$parts[] = $title;
		}
		if ($subtitle !== '')
		{
			$parts[] = $subtitle;
		}

		$fields = $document['fields'] ?? [];
		if (is_array($fields))
		{
			foreach ($fields as $code => $value)
			{
				$flat = self::flatten($value);
				if ($flat !== '')
				{
					$parts[] = $code . ': ' . $flat;
				}
			}
		}

		$text = trim(implode("\n", $parts));
		if ($text === '')
		{
			$text = trim((string)($document['entity_type'] ?? '') . ' ' . (string)($document['entity_id'] ?? ''));
		}
		if (mb_strlen($text) > $limit)
		{
			$text = mb_substr($text, 0, $limit);
		}

		return $text;
	}

	public static function hash(string $text): string
	{
		return sha1($text);
	}

	private static function flatten($value): string
	{
		if (is_array($value))
		{
			$chunks = [];
			foreach ($value as $item)
			{
				$flat = self::flatten($item);
				if ($flat !== '')
				{
					$chunks[] = $flat;
				}
			}

			return implode(', ', $chunks);
		}

		if (is_bool($value) || $value === null)
		{
			return '';
		}

		return trim((string)$value);
	}
}
