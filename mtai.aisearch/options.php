<?php
/**
 * Настройки mtai.aisearch: гибридный поиск и блок «Ответ AI».
 *
 * @global CMain $APPLICATION
 * @global string $mid
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadMessages(__FILE__);

/** @global CUser $USER */
global $USER;

$moduleId = 'mtai.aisearch';
\Bitrix\Main\Loader::includeModule($moduleId);

$POST_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($POST_RIGHT < 'R')
{
	return;
}

$request = Application::getInstance()->getContext()->getRequest();
$note = null;
$error = null;
$explain = null;

$checkboxes = ['hybrid_enabled', 'answer_enabled'];
$strings = [
	'vector_index', 'rrf_k', 'weight_bm25', 'weight_knn', 'knn_size', 'knn_min_score',
	'hybrid_min_length', 'embed_timeout', 'doc_text_limit', 'batch_size', 'max_attempts',
	'query_cache_ttl', 'answer_mode', 'answer_min_length', 'answer_debounce',
	'answer_search_sources', 'answer_kb_sources', 'answer_kb_scopes', 'answer_context_chars',
	'answer_source_chars', 'answer_system_prompt', 'answer_model', 'answer_cache_ttl',
	'answer_rate_limit', 'answer_groups',
];

if ($request->isPost() && check_bitrix_sessid() && $POST_RIGHT >= 'W')
{
	if ($request->getPost('recreate_index') !== null)
	{
		try
		{
			$dimension = (new \Mtai\AiSearch\Vector\Embedder())->dimension(true);
			(new \Mtai\AiSearch\Vector\IndexManager())->recreate($dimension);
			$note = Loc::getMessage('MTAI_AISEARCH_RECREATED');
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}
	}
	elseif ($request->getPost('test_query') !== null)
	{
		$query = trim((string)$request->getPost('test_query_text'));
		if ($query === '')
		{
			$error = Loc::getMessage('MTAI_AISEARCH_TEST_EMPTY');
		}
		else
		{
			try
			{
				$explain = (new \Mtai\AiSearch\Search\HybridSearch())->explain($query, (int)$USER->GetID());
			}
			catch (\Throwable $e)
			{
				$error = $e->getMessage();
			}
		}
	}
	elseif (
		$request->getPost('Update') !== null
		|| $request->getPost('save') !== null
		|| $request->getPost('apply') !== null
	)
	{
		foreach ($checkboxes as $name)
		{
			Option::set($moduleId, $name, $request->getPost($name) !== null ? 'Y' : 'N');
		}
		foreach ($strings as $name)
		{
			Option::set($moduleId, $name, trim((string)$request->getPost($name)));
		}
		\Mtai\AiSearch\Config::reset();
		$note = Loc::getMessage('MTAI_AISEARCH_SAVED');
	}
}

$status = [];
try
{
	$status = \Mtai\AiSearch\Status::collect();
}
catch (\Throwable $e)
{
	$error = $error ?? $e->getMessage();
}

$val = static function (string $name, string $default = '') use ($moduleId): string {
	return htmlspecialcharsbx((string)Option::get($moduleId, $name, $default));
};
$checked = static function (string $name) use ($moduleId): string {
	return Option::get($moduleId, $name) === 'Y' ? ' checked' : '';
};

$aTabs = [
	[
		'DIV' => 'tab_hybrid',
		'TAB' => Loc::getMessage('MTAI_AISEARCH_TAB_HYBRID'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_AISEARCH_TAB_HYBRID_TITLE'),
	],
	[
		'DIV' => 'tab_answer',
		'TAB' => Loc::getMessage('MTAI_AISEARCH_TAB_ANSWER'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_AISEARCH_TAB_ANSWER_TITLE'),
	],
	[
		'DIV' => 'tab_status',
		'TAB' => Loc::getMessage('MTAI_AISEARCH_TAB_STATUS'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_AISEARCH_TAB_STATUS_TITLE'),
	],
];
$tabControl = new CAdminTabControl('tabControl', $aTabs);

$rankTable = static function (array $rows): string {
	if ($rows === [])
	{
		return '<p>—</p>';
	}
	$html = '<table class="internal"><tr class="heading"><td>#</td><td>id</td><td>type</td><td>title</td><td>score</td></tr>';
	foreach (array_slice($rows, 0, 15) as $row)
	{
		$html .= '<tr><td>' . (int)$row['rank'] . '</td><td>' . htmlspecialcharsbx((string)$row['id'])
			. '</td><td>' . htmlspecialcharsbx((string)$row['type']) . '</td><td>'
			. htmlspecialcharsbx((string)$row['title']) . '</td><td>'
			. htmlspecialcharsbx((string)($row['score'] ?? '')) . '</td></tr>';
	}
	$html .= '</table>';

	return $html;
};
?>
<?php if ($note): ?>
	<div class="adm-info-message-wrap"><div class="adm-info-message"><?= htmlspecialcharsbx($note) ?></div></div>
<?php endif; ?>
<?php if ($error): ?>
	<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message"><?= htmlspecialcharsbx($error) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&amp;lang=<?= LANGUAGE_ID ?>">
	<?= bitrix_sessid_post() ?>
	<?php $tabControl->Begin(); ?>
	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td width="40%"><?= Loc::getMessage('MTAI_AISEARCH_OPT_HYBRID') ?>:</td>
		<td><input type="checkbox" name="hybrid_enabled" value="Y"<?= $checked('hybrid_enabled') ?>></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_VECTOR_INDEX') ?>:</td>
		<td><input type="text" size="40" name="vector_index" value="<?= $val('vector_index', 'bitrix24_morpho_vectors') ?>"></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_WEIGHTS') ?>:</td>
		<td>
			BM25 <input type="text" size="5" name="weight_bm25" value="<?= $val('weight_bm25', '1') ?>">
			kNN <input type="text" size="5" name="weight_knn" value="<?= $val('weight_knn', '1') ?>">
			k <input type="text" size="5" name="rrf_k" value="<?= $val('rrf_k', '60') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_KNN') ?>:</td>
		<td>
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_KNN_SIZE') ?>
			<input type="text" size="4" name="knn_size" value="<?= $val('knn_size', '20') ?>">
			min_score <input type="text" size="5" name="knn_min_score" value="<?= $val('knn_min_score', '0.35') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_HYBRID_MIN') ?>:</td>
		<td><input type="text" size="4" name="hybrid_min_length" value="<?= $val('hybrid_min_length', '3') ?>"></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_EMBED_TIMEOUT') ?>:</td>
		<td><input type="text" size="5" name="embed_timeout" value="<?= $val('embed_timeout', '1.5') ?>"> <?= Loc::getMessage('MTAI_AISEARCH_OPT_SECONDS') ?></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_DOC_LIMIT') ?>:</td>
		<td><input type="text" size="6" name="doc_text_limit" value="<?= $val('doc_text_limit', '2000') ?>"></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_BATCH') ?>:</td>
		<td>
			<input type="text" size="4" name="batch_size" value="<?= $val('batch_size', '16') ?>">
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_ATTEMPTS') ?>
			<input type="text" size="4" name="max_attempts" value="<?= $val('max_attempts', '5') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_QUERY_CACHE') ?>:</td>
		<td><input type="text" size="6" name="query_cache_ttl" value="<?= $val('query_cache_ttl', '3600') ?>"></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td width="40%"><?= Loc::getMessage('MTAI_AISEARCH_OPT_ANSWER') ?>:</td>
		<td><input type="checkbox" name="answer_enabled" value="Y"<?= $checked('answer_enabled') ?>></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_MODE') ?>:</td>
		<td>
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_MODE_HINT') ?>
			<input type="hidden" name="answer_mode" value="auto">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_ANSWER_MIN') ?>:</td>
		<td>
			<input type="text" size="4" name="answer_min_length" value="<?= $val('answer_min_length', '3') ?>">
			debounce <input type="text" size="5" name="answer_debounce" value="<?= $val('answer_debounce', '800') ?>"> мс
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_SOURCES') ?>:</td>
		<td>
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_SOURCES_SEARCH') ?>
			<input type="text" size="3" name="answer_search_sources" value="<?= $val('answer_search_sources', '5') ?>">
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_SOURCES_KB') ?>
			<input type="text" size="3" name="answer_kb_sources" value="<?= $val('answer_kb_sources', '3') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_KB_SCOPES') ?>:</td>
		<td><input type="text" size="30" name="answer_kb_scopes" value="<?= $val('answer_kb_scopes', 'public,internal') ?>"></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_CONTEXT') ?>:</td>
		<td>
			<input type="text" size="6" name="answer_context_chars" value="<?= $val('answer_context_chars', '6000') ?>">
			/ <input type="text" size="6" name="answer_source_chars" value="<?= $val('answer_source_chars', '1200') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_MODEL') ?>:</td>
		<td><input type="text" size="30" name="answer_model" value="<?= $val('answer_model', '') ?>" placeholder="<?= Loc::getMessage('MTAI_AISEARCH_OPT_MODEL_PH') ?>"></td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_CACHE_RATE') ?>:</td>
		<td>
			TTL <input type="text" size="5" name="answer_cache_ttl" value="<?= $val('answer_cache_ttl', '300') ?>">
			<?= Loc::getMessage('MTAI_AISEARCH_OPT_RATE') ?>
			<input type="text" size="4" name="answer_rate_limit" value="<?= $val('answer_rate_limit', '10') ?>">
		</td>
	</tr>
	<tr>
		<td><?= Loc::getMessage('MTAI_AISEARCH_OPT_GROUPS') ?>:</td>
		<td>
			<input type="text" size="30" name="answer_groups" value="<?= $val('answer_groups', '') ?>">
			<br><small><?= Loc::getMessage('MTAI_AISEARCH_OPT_GROUPS_HINT') ?></small>
		</td>
	</tr>
	<tr>
		<td valign="top"><?= Loc::getMessage('MTAI_AISEARCH_OPT_PROMPT') ?>:</td>
		<td><textarea name="answer_system_prompt" rows="6" cols="60"><?= $val('answer_system_prompt', '') ?></textarea></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td colspan="2">
			<p><?= Loc::getMessage('MTAI_AISEARCH_STATUS_EMBEDDER') ?>: <?= htmlspecialcharsbx((string)($status['embedder'] ?? '')) ?>
				<?php if (!empty($status['semantic'])): ?>(semantic)<?php else: ?>(lexical)<?php endif; ?>,
				dim <?= (int)($status['dimension'] ?? 0) ?></p>
			<p><?= Loc::getMessage('MTAI_AISEARCH_STATUS_VECTORS') ?>:
				<?= htmlspecialcharsbx((string)($status['vector_total'] ?? '—')) ?>
				/ <?= htmlspecialcharsbx((string)($status['morpho_total'] ?? '—')) ?>,
				<?= Loc::getMessage('MTAI_AISEARCH_STATUS_QUEUE') ?>:
				<?= htmlspecialcharsbx((string)($status['queue_pending'] ?? '—')) ?></p>
			<?php if (!empty($status['vector_error'])): ?>
				<p><?= htmlspecialcharsbx((string)$status['vector_error']) ?></p>
			<?php endif; ?>
			<?php foreach ((array)($status['problems'] ?? []) as $problem): ?>
				<div class="adm-info-message-wrap"><div class="adm-info-message"><?= htmlspecialcharsbx($problem) ?></div></div>
			<?php endforeach; ?>
			<p>
				<a class="adm-btn" href="mtai_aisearch.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('MTAI_AISEARCH_OPEN_REEMBED') ?></a>
				<input type="submit" class="adm-btn" name="recreate_index" value="<?= Loc::getMessage('MTAI_AISEARCH_BTN_RECREATE') ?>"
					onclick="return confirm('<?= htmlspecialcharsbx(Loc::getMessage('MTAI_AISEARCH_CONFIRM_RECREATE')) ?>');">
			</p>
			<p>
				<input type="text" size="40" name="test_query_text" value="<?= htmlspecialcharsbx((string)$request->getPost('test_query_text')) ?>"
					placeholder="<?= Loc::getMessage('MTAI_AISEARCH_TEST_PH') ?>">
				<input type="submit" class="adm-btn" name="test_query" value="<?= Loc::getMessage('MTAI_AISEARCH_BTN_TEST') ?>">
			</p>
			<?php if (is_array($explain)): ?>
				<?php if (!empty($explain['error'])): ?>
					<p><?= htmlspecialcharsbx((string)$explain['error']) ?></p>
				<?php endif; ?>
				<h4>BM25</h4>
				<?= $rankTable((array)($explain['bm25'] ?? [])) ?>
				<h4>kNN</h4>
				<?= $rankTable((array)($explain['knn'] ?? [])) ?>
				<h4>RRF</h4>
				<?= $rankTable((array)($explain['merged'] ?? [])) ?>
				<h4><?= Loc::getMessage('MTAI_AISEARCH_VISIBLE') ?></h4>
				<ul>
					<?php foreach ((array)($explain['visible'] ?? []) as $item): ?>
						<li><?= htmlspecialcharsbx((string)$item['type']) ?> — <?= htmlspecialcharsbx((string)$item['title']) ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</td>
	</tr>

	<?php $tabControl->Buttons(['btnSave' => true, 'btnApply' => false]); ?>
	<?php $tabControl->End(); ?>
</form>
