<?php
/**
 * Установщик mtai.aisearch.
 * Требует установленные mtai.opensearch (1.3.0+, события поиска) и mtai.aiagent.
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class mtai_aisearch extends CModule
{
	public $MODULE_ID = 'mtai.aisearch';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'R';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '');
		$this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');
		$this->MODULE_NAME = Loc::getMessage('MTAI_AISEARCH_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_AISEARCH_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('MTAI_AISEARCH_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('MTAI_AISEARCH_PARTNER_URI');
	}

	public function DoInstall(): void
	{
		global $APPLICATION;

		if (!ModuleManager::isModuleInstalled('mtai.opensearch') || !ModuleManager::isModuleInstalled('mtai.aiagent'))
		{
			$APPLICATION->ThrowException(Loc::getMessage('MTAI_AISEARCH_INSTALL_NEED_DEPS'));

			return;
		}

		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();
	}

	public function DoUninstall(): void
	{
		$this->UnInstallFiles();
		$this->UnInstallEvents();
		$this->UnInstallDB();
	}

	public function InstallDB(): bool
	{
		global $DB;

		ModuleManager::registerModule($this->MODULE_ID);

		$DB->Query(
			"CREATE TABLE IF NOT EXISTS mtai_aisearch_queue (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				DOC_ID VARCHAR(80) NOT NULL,
				ACTION VARCHAR(8) NOT NULL DEFAULT 'index',
				ATTEMPTS INT UNSIGNED NOT NULL DEFAULT 0,
				ERROR TEXT NULL,
				DATE_CREATE DATETIME NOT NULL,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_MTAI_AS_DOC (DOC_ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			true,
			__FILE__
		);

		return true;
	}

	public function UnInstallDB(): bool
	{
		global $DB;

		$DB->Query('DROP TABLE IF EXISTS mtai_aisearch_queue', true, __FILE__);
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallEvents(): bool
	{
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
		\Mtai\AiSearch\EventHandler::register();

		return true;
	}

	public function UnInstallEvents(): bool
	{
		if (\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
		{
			\Mtai\AiSearch\EventHandler::unregister();
		}

		return true;
	}

	public function InstallFiles(): bool
	{
		$stubPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/mtai_aisearch.php';
		CheckDirPath(dirname($stubPath) . '/');
		file_put_contents(
			$stubPath,
			'<?php require($_SERVER["DOCUMENT_ROOT"] . "/local/modules/mtai.aisearch/install/admin/mtai_aisearch.php");'
			. "\n"
		);

		return true;
	}

	public function UnInstallFiles(): bool
	{
		DeleteDirFilesEx('/bitrix/js/mtai.aisearch');
		$stub = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/mtai_aisearch.php';
		if (is_file($stub))
		{
			unlink($stub);
		}

		return true;
	}
}
