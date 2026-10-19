<?php

/**
 * Значения опций по умолчанию.
 * Переменная нужна ядру (Option::getDefaults), return — классу Config.
 */
$mtai_aisearch_default_option = [
	// Гибридный поиск выключен, пока не посчитаны векторы.
	'hybrid_enabled' => 'N',
	'vector_index' => 'bitrix24_morpho_vectors',
	'rrf_k' => '60',
	'weight_bm25' => '1',
	'weight_knn' => '1',
	'knn_size' => '20',
	'knn_min_score' => '0.35',
	'knn_outlier_z' => '3.5',
	'knn_semantic_max' => '3',
	'hybrid_min_length' => '3',
	'embed_timeout' => '1.5',
	'doc_text_limit' => '2000',
	'batch_size' => '16',
	'max_attempts' => '5',
	'query_cache_ttl' => '3600',
];

return $mtai_aisearch_default_option;
