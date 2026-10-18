<?php

namespace Mtai\AiSearch\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Mtai\AiSearch\AiSearchException;
use Mtai\AiSearch\Answer\Answerer;

/**
 * mtai:aisearch.api.answer.ask — блок «Ответ AI» в подсказке шапки.
 */
class Answer extends Controller
{
	public function configureActions()
	{
		return [
			'ask' => [
				'+prefilters' => [new CloseSession()],
			],
		];
	}

	public function askAction(string $query): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			$this->addError(new Error('Требуется авторизация', 'AUTH_REQUIRED'));

			return null;
		}

		try
		{
			return (new Answerer())->ask($query, $userId);
		}
		catch (AiSearchException $e)
		{
			$this->addError(new Error($e->getMessage(), $e->getErrorCode()));

			return null;
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'INTERNAL'));

			return null;
		}
	}
}
