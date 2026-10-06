<?php

namespace App\Exceptions\Domain;

use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(public readonly int $walletId)
    {
        parent::__construct("Wallet {$walletId} has insufficient funds for this operation.");
    }
}
