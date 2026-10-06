<?php

namespace App\Services\Vtu;

interface VtuProvider
{
    /**
     * @throws VtuProviderException
     */
    public function purchase(string $type, string $recipient, int $amountMinorUnits): VtuPurchaseResult;
}
