<?php
/**
 * Пересчёт векторов гибридного поиска. Работа идёт в ajax-экшенах модуля.
 */

use Bitrix\Main\Localization\Loc;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.aisearch';
$POST_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($POST_RIGHT < 'W')
{
	$APPLICATION->AuthForm(Loc::getMessage('MTAI_AISEARCH_ACCESS_DENIED'));
}

\Bitrix\Main\Loader::includeModule($moduleId);
$APPLICATION->SetTitle(Loc::getMessage('MTAI_AISEARCH_ADMIN_TITLE'));

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div id="mtai-aisearch-admin" style="max-width: 900px;">
	<div class="adm-info-message-wrap">
		<div class="adm-info-message"><?= Loc::getMessage('MTAI_AISEARCH_ADMIN_INTRO') ?></div>
	</div>
	<h3><?= Loc::getMessage('MTAI_AISEARCH_ADMIN_STATUS') ?></h3>
	<pre id="mtai-as-status" style="white-space: pre-wrap;"></pre>
	<p>
		<input type="button" class="adm-btn" id="mtai-as-refresh" value="<?= Loc::getMessage('MTAI_AISEARCH_ADMIN_REFRESH') ?>">
		<a class="adm-btn" href="settings.php?mid=mtai.aisearch&lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('MTAI_AISEARCH_ADMIN_SETTINGS') ?></a>
	</p>
	<h3><?= Loc::getMessage('MTAI_AISEARCH_ADMIN_REEMBED') ?></h3>
	<p>
		<label><input type="checkbox" id="mtai-as-recreate"> <?= Loc::getMessage('MTAI_AISEARCH_ADMIN_RECREATE') ?></label>
	</p>
	<p>
		<input type="button" class="adm-btn adm-btn-save" id="mtai-as-start" value="<?= Loc::getMessage('MTAI_AISEARCH_ADMIN_START') ?>">
		<span id="mtai-as-state"></span>
	</p>
	<div id="mtai-as-log" style="margin-top: 12px; max-height: 260px; overflow: auto;"></div>
</div>
<script>
(function () {
	'use strict';

	var logBox = document.getElementById('mtai-as-log');
	var stateBox = document.getElementById('mtai-as-state');

	function runAction(action, data) {
		return BX.ajax.runAction('mtai:aisearch.api.maintenance.' + action, {data: data || {}});
	}

	function log(text) {
		var row = document.createElement('div');
		row.style.padding = '2px 8px';
		row.textContent = new Date().toLocaleTimeString() + ' — ' + text;
		logBox.appendChild(row);
		logBox.scrollTop = logBox.scrollHeight;
	}

	function refresh() {
		runAction('status').then(function (response) {
			document.getElementById('mtai-as-status').textContent = JSON.stringify(response.data, null, 2);
		}, function (response) {
			log((response.errors && response.errors[0] && response.errors[0].message) || 'status error');
		});
	}

	function step() {
		runAction('reembedStep').then(function (response) {
			var data = response.data || {};
			stateBox.textContent = 'left ' + data.left + ', errors ' + data.errors;
			log('batch ' + data.processed + ', left ' + data.left + ', errors ' + data.errors);
			if (data.left > 0 && data.processed > 0) {
				step();
				return;
			}
			stateBox.textContent = data.left > 0 ? 'остановлено' : 'готово';
			refresh();
		}, function (response) {
			stateBox.textContent = 'ошибка';
			log((response.errors && response.errors[0] && response.errors[0].message) || 'step error');
		});
	}

	document.getElementById('mtai-as-refresh').addEventListener('click', refresh);
	document.getElementById('mtai-as-start').addEventListener('click', function () {
		var recreate = document.getElementById('mtai-as-recreate').checked ? 'Y' : 'N';
		if (recreate === 'Y' && !window.confirm('Пересоздать векторный индекс? Текущие векторы будут удалены.')) {
			return;
		}
		stateBox.textContent = 'постановка в очередь…';
		runAction('reembedStart', {recreate: recreate}).then(function (response) {
			log('в очереди: ' + (response.data && response.data.queued));
			step();
		}, function (response) {
			stateBox.textContent = 'ошибка';
			log((response.errors && response.errors[0] && response.errors[0].message) || 'start error');
		});
	});

	refresh();
})();
</script>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
