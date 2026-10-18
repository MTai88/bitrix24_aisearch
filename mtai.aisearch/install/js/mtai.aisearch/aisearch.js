/**
 * Блок «Ответ AI» в выпадающей подсказке поиска шапки.
 * Выдача сущностей по-прежнему рисуется патчем mtai.opensearch.
 */
;(function () {
	'use strict';

	var cfg = window.MtaiAiSearchConfig || {};
	var mode = cfg.mode === 'auto' ? 'auto' : 'manual';
	var minLength = parseInt(cfg.minLength, 10) || 3;
	var debounceMs = parseInt(cfg.debounce, 10) || 800;
	var TOOLS_ID = 'search-title-block-tools';
	var BLOCK_CLASS = 'mtai-aisearch-block';

	var timer = null;
	var generation = 0;
	var answeredQuery = '';
	var lastHtml = '';

	function inputEl() {
		return document.getElementById('search-textbox-input');
	}

	function queryText() {
		var input = inputEl();
		return input ? String(input.value || '').trim() : '';
	}

	function toolsEl() {
		return document.getElementById(TOOLS_ID);
	}

	function removeBlock() {
		var nodes = document.querySelectorAll('.' + BLOCK_CLASS);
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].parentNode) {
				nodes[i].parentNode.removeChild(nodes[i]);
			}
		}
	}

	function ensureBlock() {
		var tools = toolsEl();
		if (!tools || !tools.parentNode) {
			return null;
		}
		var existing = tools.parentNode.querySelector('.' + BLOCK_CLASS);
		if (existing) {
			return existing;
		}
		var block = document.createElement('div');
		block.className = 'search-title-top-block ' + BLOCK_CLASS;
		block.innerHTML = '<div class="mtai-aisearch-title">Ответ AI</div><div class="mtai-aisearch-body"></div>';
		tools.parentNode.insertBefore(block, tools);
		return block;
	}

	function renderBody(block, html) {
		var body = block.querySelector('.mtai-aisearch-body');
		if (body) {
			body.innerHTML = html;
		}
	}

	function sourceList(sources) {
		if (!sources || !sources.length) {
			return '';
		}
		var ul = document.createElement('ul');
		ul.className = 'mtai-aisearch-sources';
		for (var i = 0; i < sources.length; i++) {
			var source = sources[i];
			var li = document.createElement('li');
			var label = '[' + source.n + '] ' + (source.title || '');
			if (source.url) {
				var link = document.createElement('a');
				link.href = source.url;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = label;
				li.appendChild(link);
			} else {
				li.textContent = label;
			}
			ul.appendChild(li);
		}
		return ul.outerHTML;
	}

	function showButton() {
		var block = ensureBlock();
		if (!block || block.querySelector('.mtai-aisearch-btn')) {
			return;
		}
		renderBody(
			block,
			'<button type="button" class="ui-btn ui-btn-xs ui-btn-primary mtai-aisearch-btn">Спросить AI</button>'
		);
		var button = block.querySelector('.mtai-aisearch-btn');
		if (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				ask(queryText());
			});
		}
	}

	function restoreAnswer() {
		if (!lastHtml || queryText() !== answeredQuery) {
			return;
		}
		var block = ensureBlock();
		if (block && !block.querySelector('.mtai-aisearch-answer')) {
			renderBody(block, lastHtml);
		}
	}

	function ask(query) {
		if (!query || query.length < minLength) {
			return;
		}
		var block = ensureBlock();
		if (!block || !window.BX || !BX.ajax || typeof BX.ajax.runAction !== 'function') {
			return;
		}

		var token = ++generation;
		renderBody(block, '<div class="mtai-aisearch-status">Ищу ответ…</div>');

		BX.ajax.runAction('mtai:aisearch.api.answer.ask', {data: {query: query}}).then(
			function (response) {
				if (token !== generation || queryText() !== query) {
					return;
				}
				var data = response && response.data ? response.data : {};
				lastHtml = '<div class="mtai-aisearch-answer">' + (data.answer || '') + '</div>'
					+ sourceList(data.sources);
				answeredQuery = query;
				var current = ensureBlock();
				if (current) {
					renderBody(current, lastHtml);
				}
			},
			function () {
				if (token !== generation) {
					return;
				}
				lastHtml = '';
				answeredQuery = '';
				removeBlock();
			}
		);
	}

	function onInput() {
		var query = queryText();
		if (query !== answeredQuery) {
			generation++;
			lastHtml = '';
			answeredQuery = '';
			removeBlock();
		}
		if (query.length < minLength || !toolsEl()) {
			return;
		}
		if (mode === 'auto') {
			if (timer) {
				clearTimeout(timer);
			}
			timer = setTimeout(function () {
				var current = queryText();
				if (current.length >= minLength && current !== answeredQuery) {
					ask(current);
				}
			}, debounceMs);
			return;
		}
		showButton();
	}

	function onDropdown() {
		if (!toolsEl()) {
			return;
		}
		if (lastHtml && queryText() === answeredQuery) {
			restoreAnswer();
			return;
		}
		if (mode === 'manual' && queryText().length >= minLength) {
			showButton();
		}
	}

	function bindInput() {
		var input = inputEl();
		if (!input || input.__mtaiAiSearch) {
			return;
		}
		input.__mtaiAiSearch = true;
		input.addEventListener('input', onInput);
	}

	function start() {
		bindInput();
		if (!document.body) {
			return;
		}
		var observer = new MutationObserver(function () {
			bindInput();
			onDropdown();
		});
		observer.observe(document.body, {childList: true, subtree: true});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
