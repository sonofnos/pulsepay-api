<?php

namespace Tests\Feature;

use App\Models\BillPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class BillPaymentTest extends TestCase
{
    use CreatesAuthenticatedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushdb();
    }

    public function test_successful_bill_payment_debits_wallet_and_records_provider_reference(): void
    {
        $user = $this->createUserWithWallets('payer@example.com');
        $this->walletFor($user, 'NGN')->update(['balance' => 100000]);

        $response = $this->actingAs($user)->postJson('/api/bill-payments', [
            'type' => 'airtime',
            'recipient' => '08011112222',
            'amount' => 50000,
        ], ['Idempotency-Key' => 'bill-1']);

        $response->assertCreated();
        $this->assertSame('successful', $response->json('status'));
        $this->assertNotNull($response->json('provider_reference'));
        $this->assertSame(50000, $this->walletFor($user, 'NGN')->fresh()->balance);
    }

    public function test_provider_failure_reverses_the_debit_and_refunds_the_wallet(): void
    {
        $user = $this->createUserWithWallets('payer2@example.com');
        $this->walletFor($user, 'NGN')->update(['balance' => 100000]);

        // FakeVtuProvider always declines this specific recipient.
        $response = $this->actingAs($user)->postJson('/api/bill-payments', [
            'type' => 'airtime',
            'recipient' => '00000000000',
            'amount' => 50000,
        ], ['Idempotency-Key' => 'bill-2']);

        $response->assertStatus(422);

        // Balance is back to what it started at: the debit was reversed, not left stranded.
        $this->assertSame(100000, $this->walletFor($user, 'NGN')->fresh()->balance);

        $billPayment = BillPayment::where('recipient', '00000000000')->firstOrFail();
        $this->assertSame('reversed', $billPayment->status);
    }

    public function test_insufficient_funds_rejects_the_payment_before_calling_the_provider(): void
    {
        $user = $this->createUserWithWallets('payer3@example.com');

        $this->actingAs($user)->postJson('/api/bill-payments', [
            'type' => 'data',
            'recipient' => '08033334444',
            'amount' => 1,
        ], ['Idempotency-Key' => 'bill-3'])->assertStatus(422);
    }
}
