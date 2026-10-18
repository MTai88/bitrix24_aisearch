<?php

namespace Mtai\AiSearch\Answer;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Mtai\AiSearch\AiSearchException;
use Mtai\AiSearch\Config;

/**
 * Ответ по источникам. Кэш и лимит запросов — на пользователя,
 * чтобы выдача одного сотрудника не попала другому.
 */
final class Answerer
{
	/**
	 * @return array{answer: string, sources: array<int, array{n: int, title: string, url: string, type: string}>, cached: bool}
	 */
	public function ask(string $query, int $userId): array
	{
		$query = trim($query);
		if (mb_strlen($query) > 500)
		{
			$query = mb_substr($query, 0, 500);
		}
		if ($query === '')
		{
			throw new AiSearchException('Пустой запрос', 'DISABLED');
		}
		if (!Config::answerEnabled())
		{
			throw new AiSearchException('AI-ответ выключен', 'DISABLED');
		}
		if (!$this->allowed($userId))
		{
			throw new AiSearchException('Недостаточно прав для AI-ответа', 'DISABLED');
		}
		if (!Loader::includeModule('mtai.aiagent'))
		{
			throw new AiSearchException('Модуль mtai.aiagent не установлен', 'DISABLED');
		}

		$cached = $this->cacheGet($userId, $query);
		if ($cached !== null)
		{
			$cached['cached'] = true;

			return $cached;
		}

		$this->hitRate($userId);

		$sources = (new Retriever())->retrieve($query, $userId);
		if ($sources === [])
		{
			$result = [
				'answer' => 'По этому запросу ничего не нашлось.',
				'sources' => [],
				'cached' => false,
			];
			$this->cachePut($userId, $query, $result);

			return $result;
		}

		$messages = (new PromptBuilder())->messages($query, $sources);
		try
		{
			$provider = \Mtai\Aiagent\Llm\Factory::create();
			$options = [];
			if (Config::answerModel() !== '')
			{
				$options['model'] = Config::answerModel();
			}
			$chat = $provider->chat($messages, [], $options);
			$text = trim((string)($chat['content'] ?? ''));
			if ($text === '')
			{
				$text = 'Модель не вернула ответ.';
			}
		}
		catch (\Throwable $e)
		{
			\Mtai\Aiagent\Logger::error('aisearch', $e->getMessage());

			throw new AiSearchException('Не удалось получить ответ модели', 'LLM_ERROR');
		}

		\Mtai\Aiagent\Logger::info('aisearch', 'answer', [
			'user' => $userId,
			'query' => mb_substr($query, 0, 200),
			'sources' => count($sources),
		]);

		$publicSources = [];
		foreach ($sources as $source)
		{
			$publicSources[] = [
				'n' => $source['n'],
				'title' => $source['title'],
				'url' => $source['url'],
				'type' => $source['type'],
			];
		}

		$result = [
			'answer' => Html::fromMarkdown($text, $sources),
			'sources' => $publicSources,
			'cached' => false,
		];
		$this->cachePut($userId, $query, $result);

		return $result;
	}

	public function allowed(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$groups = Config::answerGroups();
		if ($groups === [])
		{
			return true;
		}

		global $USER;
		if (isset($USER) && $USER instanceof \CUser && (int)$USER->GetID() === $userId && $USER->IsAdmin())
		{
			return true;
		}

		$userGroups = array_map('intval', \CUser::GetUserGroup($userId));

		return array_intersect($groups, $userGroups) !== [];
	}

	private function hitRate(int $userId): void
	{
		$limit = Config::answerRateLimit();
		if ($limit <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$key = 'rate_' . $userId . '_' . date('YmdHi');
		$dir = 'mtai.aisearch/rate';
		$count = 0;
		if ($cache->initCache(90, $key, $dir))
		{
			$count = (int)($cache->getVars()['c'] ?? 0);
			$cache->abortDataCache();
		}
		if ($count >= $limit)
		{
			throw new AiSearchException('Слишком много запросов, подождите минуту', 'RATE_LIMIT');
		}

		$count++;
		$cache->clean($key, $dir);
		if ($cache->startDataCache(90, $key, $dir))
		{
			$cache->endDataCache(['c' => $count]);
		}
	}

	/** @return array{answer: string, sources: array, cached: bool}|null */
	private function cacheGet(int $userId, string $query): ?array
	{
		$ttl = Config::answerCacheTtl();
		if ($ttl <= 0)
		{
			return null;
		}

		$cache = Cache::createInstance();
		if (!$cache->initCache($ttl, $this->cacheKey($userId, $query), 'mtai.aisearch/answer'))
		{
			return null;
		}

		$vars = $cache->getVars();

		return is_array($vars) && isset($vars['answer']) ? $vars : null;
	}

	/** @param array{answer: string, sources: array, cached: bool} $result */
	private function cachePut(int $userId, string $query, array $result): void
	{
		$ttl = Config::answerCacheTtl();
		if ($ttl <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$key = $this->cacheKey($userId, $query);
		$dir = 'mtai.aisearch/answer';
		if ($cache->startDataCache($ttl, $key, $dir))
		{
			$stored = $result;
			$stored['cached'] = false;
			$cache->endDataCache($stored);
		}
	}

	private function cacheKey(int $userId, string $query): string
	{
		return md5($userId . '|' . mb_strtolower($query));
	}
}
