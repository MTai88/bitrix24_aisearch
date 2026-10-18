<?php

namespace Mtai\AiSearch\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Mtai\AiSearch\Search\HybridSearch;
use Mtai\AiSearch\Status;
use Mtai\AiSearch\Vector\Embedder;
use Mtai\AiSearch\Vector\IndexManager;
use Mtai\AiSearch\Vector\Queue;

/**
 * Служебные экшены страницы пересчёта векторов (только админы).
 * mtai:aisearch.api.maintenance.<action>
 */
class Maintenance extends Controller
{
	public function configureActions()
	{
		return [
			'status' => [
				'+prefilters' => [new CloseSession()],
			],
			'reembedStart' => [],
			'reembedStep' => [],
			'explain' => [],
		];
	}

	private function requireAdmin(): bool
	{
		if (!$this->getCurrentUser()->isAdmin())
		{
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return false;
		}

		return true;
	}

	public function statusAction(): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return Status::collect();
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'INTERNAL'));

			return null;
		}
	}

	/**
	 * Ставит в очередь все документы морфологического индекса.
	 * $recreate = true удаляет kNN-индекс и создаёт его заново.
	 */
	public function reembedStartAction(string $recreate = 'N'): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			if ($recreate === 'Y')
			{
				$dimension = (new Embedder())->dimension(true);
				(new IndexManager())->recreate($dimension);
			}

			$queued = (new Queue())->enqueueAll();

			return ['queued' => $queued];
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'INTERNAL'));

			return null;
		}
	}

	public function reembedStepAction(): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return (new Queue())->processBatch();
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'INTERNAL'));

			return null;
		}
	}

	public function explainAction(string $query): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return (new HybridSearch())->explain($query, (int)$this->getCurrentUser()->getId());
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'INTERNAL'));

			return null;
		}
	}
}
