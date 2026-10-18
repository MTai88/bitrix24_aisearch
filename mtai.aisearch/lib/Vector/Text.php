<?php

namespace Mtai\AiSearch\Vector;

use Mtai\AiSearch\Config;

/**
 * Текст документа индекса mtai.opensearch, из которого считается эмбеддинг.
 * Из полей убирается служебный шум CRM: обрезки телефонов, суммы, даты,
 * ROT13-дубли и коды полей. Иначе все документы похожи друг на друга.
 */
final class Text
{
	public static function fromDocument(array $document, ?int $limit = null): string
	{
		$limit ??= Config::docTextLimit();
		$title = trim((string)($document['title'] ?? ''));
		$subtitle = trim((string)($document['subtitle'] ?? ''));
		$parts = [];

		$label = self::typeLabel($document);
		if ($label !== '')
		{
			$parts[] = $label;
		}
		if ($title !== '')
		{
			$parts[] = $title;
		}
		if ($subtitle !== '')
		{
			$parts[] = $subtitle;
		}

		$collected = implode("\n", $parts);
		$fields = $document['fields'] ?? [];
		if (is_array($fields))
		{
			foreach ($fields as $code => $value)
			{
				$code = (string)$code;
				if ($code === '' || str_ends_with($code, '.en'))
				{
					continue;
				}
				$flat = self::flatten($value);
				if ($flat === '')
				{
					continue;
				}
				$clean = self::clean($flat, $code === 'content');
				$clean = self::withoutTitleWords($clean, $title . "\n" . $collected);
				if ($clean === '' || mb_stripos($collected, $clean) !== false)
				{
					continue;
				}
				$parts[] = $clean;
				$collected .= "\n" . $clean;
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

	private static function typeLabel(array $document): string
	{
		$type = (string)($document['entity_type'] ?? '');
		$attrs = (array)($document['attrs'] ?? []);
		$iblockTitle = trim((string)($attrs['iblockTitle'] ?? ''));

		return match ($type) {
			'crm_deal' => 'Сделка',
			'crm_lead' => 'Лид',
			'crm_contact' => 'Контакт',
			'crm_company' => 'Компания',
			'user' => 'Сотрудник',
			'iblock' => 'Элемент инфоблока' . ($iblockTitle !== '' ? ' ' . $iblockTitle : ''),
			'iblock_section' => 'Раздел инфоблока' . ($iblockTitle !== '' ? ' ' . $iblockTitle : ''),
			default => str_starts_with($type, 'crm_') ? 'Смарт-процесс' : '',
		};
	}

	private static function clean(string $text, bool $dropRot13): string
	{
		$text = preg_replace('/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/u', ' ', $text) ?? $text;
		$text = preg_replace('/\b\d+[.,]\d+\b/u', ' ', $text) ?? $text;
		$text = preg_replace('/\b\d{3,}\b/u', ' ', $text) ?? $text;
		if ($dropRot13)
		{
			$text = preg_replace('/\b[a-z]{2,}\b/', ' ', $text) ?? $text;
		}
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;

		return trim($text);
	}

	private static function withoutTitleWords(string $text, string $title): string
	{
		$known = self::tokenSet($title);
		if ($known === [])
		{
			return $text;
		}
		$words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($words))
		{
			return $text;
		}
		$kept = [];
		foreach ($words as $word)
		{
			$key = mb_strtolower(trim($word, " \t\n\r\0\x0B\"'«».,;:!?()"));
			if ($key !== '' && isset($known[$key]))
			{
				continue;
			}
			$kept[] = $word;
		}

		return trim(implode(' ', $kept));
	}

	/** @return array<string, true> */
	private static function tokenSet(string $text): array
	{
		$words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
		$set = [];
		if (is_array($words))
		{
			foreach ($words as $word)
			{
				if (mb_strlen($word) >= 3)
				{
					$set[$word] = true;
				}
			}
		}

		return $set;
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
