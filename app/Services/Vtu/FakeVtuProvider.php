<?php

namespace App\Services\Vtu;

use Illuminate\Support\Str;

// The recipient "00000000000" always fails, used by the reversal-path tests.
class FakeVtuProvider implements VtuProvider
{
    private const ALWAYS_FAILS_RECIPIENT = '00000000000';

    public function purchase(string $type, string $recipient, int $amountMinorUnits): VtuPurchaseResult
    {
        if ($recipient === self::ALWAYS_FAILS_RECIPIENT) {
            throw new VtuProviderException("Provider declined the {$type} purchase for {$recipient}.");
        }

        return new VtuPurchaseResult(providerReference: 'FAKE-'.Str::upper(Str::random(10)));
    }
}
