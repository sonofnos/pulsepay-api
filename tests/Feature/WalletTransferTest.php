<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class WalletTransferTest extends TestCase
{
    use CreatesAuthenticatedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushdb();
    }

    public function test_transfer_moves_balance_as_a_balanced_debit_credit_pair(): void
    {
        $sender = $this->createUserWithWallets('sender@example.com');
        $recipient = $this->createUserWithWallets('recipient@example.com');
        $this->walletFor($sender, 'NGN')->update(['balance' => 500000]);

        $response = $this->actingAs($sender)->postJson('/api/wallets/transfer', [
            'recipient_email' => 'recipient@example.com',
            'currency' => 'NGN',
            'amount' => 150000,
        ], ['Idempotency-Key' => 'transfer-1']);

        $response->assertOk();

        $this->assertSame(350000, $this->walletFor($sender, 'NGN')->fresh()->balance);
        $this->assertSame(150000, $this->walletFor($recipient, 'NGN')->fresh()->balance);

        $txId = $response->json('transaction_id');
        $entries = LedgerEntry::where('transaction_id', $txId)->get();
        $this->assertCount(2, $entries);
        $this->assertSame(0, $entries->sum(fn ($e) => $e->direction === 'credit' ? $e->amount : -$e->amount));
    }

    public function test_transfer_is_rejected_when_balance_is_insufficient_and_moves_nothing(): void
    {
        $sender = $this->createUserWithWallets('poor@example.com');
        $recipient = $this->createUserWithWallets('rich@example.com');

        $response = $this->actingAs($sender)->postJson('/api/wallets/transfer', [
            'recipient_email' => 'rich@example.com',
            'currency' => 'NGN',
            'amount' => 1,
        ], ['Idempotency-Key' => 'transfer-2']);

        $response->assertStatus(422);
        $this->assertSame(0, $this->walletFor($sender, 'NGN')->fresh()->balance);
    }

    public function test_replaying_the_same_idempotency_key_and_body_does_not_double_process(): void
    {
        $sender = $this->createUserWithWallets('a@example.com');
        $recipient = $this->createUserWithWallets('b@example.com');
        $this->walletFor($sender, 'NGN')->update(['balance' => 100000]);

        $payload = ['recipient_email' => 'b@example.com', 'currency' => 'NGN', 'amount' => 50000];
        $headers = ['Idempotency-Key' => 'same-key'];

        $first = $this->actingAs($sender)->postJson('/api/wallets/transfer', $payload, $headers);
        $second = $this->actingAs($sender)->postJson('/api/wallets/transfer', $payload, $headers);

        $first->assertOk();
        $second->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('transaction_id'), $second->json('transaction_id'));

        // Balance only moved once, not twice.
        $this->assertSame(50000, $this->walletFor($sender, 'NGN')->fresh()->balance);
    }

    public function test_reusing_the_same_idempotency_key_with_a_different_body_is_rejected(): void
    {
        $sender = $this->createUserWithWallets('c@example.com');
        $this->createUserWithWallets('d@example.com');
        $this->walletFor($sender, 'NGN')->update(['balance' => 100000]);

        $headers = ['Idempotency-Key' => 'reused-key'];

        $this->actingAs($sender)->postJson('/api/wallets/transfer', [
            'recipient_email' => 'd@example.com', 'currency' => 'NGN', 'amount' => 1000,
        ], $headers)->assertOk();

        $this->actingAs($sender)->postJson('/api/wallets/transfer', [
            'recipient_email' => 'd@example.com', 'currency' => 'NGN', 'amount' => 2000,
        ], $headers)->assertStatus(409);
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $sender = $this->createUserWithWallets('e@example.com');
        $this->createUserWithWallets('f@example.com');

        $this->actingAs($sender)->postJson('/api/wallets/transfer', [
            'recipient_email' => 'f@example.com', 'currency' => 'NGN', 'amount' => 1000,
        ])->assertStatus(400);
    }
}
