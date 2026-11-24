<?php

namespace App\Services;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
