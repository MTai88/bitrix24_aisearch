<?php

namespace Mtai\AiSearch\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Type\DateTime;

/** Очередь эмбеддинга: одна строка на документ индекса opensearch. */
class QueueTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'mtai_aisearch_queue';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\StringField('DOC_ID'))
				->configureRequired()
				->configureSize(80),
			(new \Bitrix\Main\Entity\StringField('ACTION'))
				->configureRequired()
				->configureSize(8)
				->configureDefaultValue('index'),
			(new \Bitrix\Main\Entity\IntegerField('ATTEMPTS'))
				->configureDefaultValue(0),
			(new \Bitrix\Main\Entity\TextField('ERROR')),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn () => new DateTime()),
		];
	}
}
