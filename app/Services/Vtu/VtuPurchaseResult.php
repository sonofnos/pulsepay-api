<?php

namespace App\Services\Vtu;

final class VtuPurchaseResult
{
    public function __construct(
        public readonly string $providerReference,
    ) {}
}
