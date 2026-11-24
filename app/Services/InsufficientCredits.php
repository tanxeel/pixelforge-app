<?php

namespace App\Services;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct('Not enough credits.');
    }
}
