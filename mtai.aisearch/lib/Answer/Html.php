<?php

namespace Mtai\AiSearch\Answer;

/**
 * Ответ модели → безопасный HTML: сначала экранирование, потом
 * жирный, переносы и ссылки [n] на источники.
 */
final class Html
{
	/**
	 * @param array<int, array{n: int, url: string}> $sources
	 */
	public static function fromMarkdown(string $text, array $sources): string
	{
		$safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;

		$byNumber = [];
		foreach ($sources as $source)
		{
			$byNumber[(int)$source['n']] = (string)($source['url'] ?? '');
		}

		$safe = preg_replace_callback(
			'/\[(\d+)\]/',
			static function (array $match) use ($byNumber): string {
				$n = (int)$match[1];
				$url = self::safeUrl($byNumber[$n] ?? '');
				if ($url === '')
				{
					return '[' . $n . ']';
				}

				return '<a href="' . $url . '" target="_blank" rel="noopener">[' . $n . ']</a>';
			},
			$safe
		) ?? $safe;

		return nl2br($safe, false);
	}

	private static function safeUrl(string $url): string
	{
		$url = trim($url);
		if ($url === '' || str_starts_with($url, 'javascript:') || str_starts_with($url, 'data:'))
		{
			return '';
		}
		if (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url))
		{
			return '';
		}

		return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
