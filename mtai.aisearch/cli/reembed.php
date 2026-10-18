<?php
/**
 * CLI: полный пересчёт векторов гибридного поиска.
 *
 *   docker compose exec php php /var/www/html/local/modules/mtai.aisearch/cli/reembed.php
 *   ... reembed.php --keep   # не пересоздавать kNN-индекс
 */

if (PHP_SAPI !== 'cli')
{
	die('CLI only');
}

// 4 уровня — модуль скопирован в local/modules/mtai.aisearch.
// 5 уровней — каталог модуля внутри репозитория и подключён симлинком
// (PHP отдаёт реальный путь, а не путь симлинка).
$documentRoot = dirname(__DIR__, 4);
if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php'))
{
	$documentRoot = dirname(__DIR__, 5);
}
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('CHK_EVENT', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Mtai\AiSearch\Vector\Embedder;
use Mtai\AiSearch\Vector\IndexManager;
use Mtai\AiSearch\Vector\Queue;

try
{
	if (!\Bitrix\Main\Loader::includeModule('mtai.aisearch'))
	{
		fwrite(STDERR, "Module mtai.aisearch is not installed\n");
		exit(1);
	}
	if (!\Bitrix\Main\Loader::includeModule('mtai.opensearch'))
	{
		fwrite(STDERR, "Module mtai.opensearch is not installed\n");
		exit(1);
	}

	$keep = in_array('--keep', $argv ?? [], true);
	$embedder = new Embedder();
	$dimension = $embedder->dimension(true);
	$index = new IndexManager();

	if (!$keep)
	{
		echo 'Recreating vector index ' . \Mtai\AiSearch\Config::vectorIndex() . " (dim {$dimension})...\n";
		$index->recreate($dimension);
	}
	else
	{
		echo "Keeping existing vector index, dim {$dimension}\n";
		$index->ensure($dimension);
	}

	$queue = new Queue();
	$queued = $queue->enqueueAll();
	echo "Queued {$queued} documents\n";

	while (true)
	{
		$step = $queue->processBatch();
		echo "\r  processed batch {$step['processed']}, left {$step['left']}, errors {$step['errors']}   ";
		if ($step['left'] <= 0 || ($step['processed'] === 0 && $step['errors'] > 0))
		{
			echo "\n";
			if ($step['errors'] > 0 && $step['left'] > 0)
			{
				fwrite(STDERR, "Stopped: batch failed, {$step['left']} left in queue\n");
				exit(1);
			}
			break;
		}
	}

	echo 'Vectors: ' . $index->count() . "\n";
}
catch (\Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
