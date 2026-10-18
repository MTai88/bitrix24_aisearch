<?php

namespace Mtai\AiSearch;

use Mtai\AiSearch\Vector\Queue;

/** Раз в минуту обрабатывает один батч очереди эмбеддинга. */
final class Agent
{
	public static function processQueue(): string
	{
		try
		{
			(new Queue())->processBatch();
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aisearch agent: ' . $e->getMessage(), Config::MODULE_ID);
		}

		return '\\Mtai\\AiSearch\\Agent::processQueue();';
	}
}
