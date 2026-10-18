<?php

namespace Mtai\AiSearch;

/**
 * Ошибка модуля с кодом для ajax-ответа
 * (DISABLED, RATE_LIMIT, LLM_ERROR, EMBEDDER_UNAVAILABLE, DIMENSION_MISMATCH).
 */
final class AiSearchException extends \RuntimeException
{
	public function __construct(string $message, private string $errorCode = 'INTERNAL')
	{
		parent::__construct($message);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
