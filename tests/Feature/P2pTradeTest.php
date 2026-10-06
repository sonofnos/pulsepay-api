<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class P2pTradeTest extends TestCase
{
    use CreatesAuthenticatedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushdb();
    }

    public function test_full_sell_offer_trade_lifecycle_moves_funds_correctly(): void
    {
        $maker = $this->createUserWithWallets('maker@example.com');
        $taker = $this->createUserWithWallets('taker@example.com');
        $this->walletFor($maker, 'USDT')->update(['balance' => 1_000_000]);

        $offer = $this->actingAs($maker)->postJson('/api/p2p/offers', [
            'side' => 'sell', 'currency' => 'USDT', 'rate' => 1500,
            'amount' => 500_000, 'min_order_amount' => 10_000, 'max_order_amount' => 500_000,
        ])->assertCreated()->json();

        // Maker's spendable balance drops only at trade time, not at offer creation.
        $this->assertSame(1_000_000, $this->walletFor($maker, 'USDT')->fresh()->balance);

        $trade = $this->actingAs($taker)->postJson("/api/p2p/offers/{$offer['id']}/trades", [
            'amount' => 200_000,
        ], ['Idempotency-Key' => 'trade-1'])->assertCreated()->json();

        $this->assertSame(800_000, $this->walletFor($maker, 'USDT')->fresh()->balance);
        $this->assertSame(0, $this->walletFor($taker, 'USDT')->fresh()->balance);

        $this->actingAs($maker)->postJson("/api/p2p/trades/{$trade['id']}/release", [], ['Idempotency-Key' => 'release-early'])
            ->assertStatus(422); // seller can't release before buyer confirms payment

        $this->actingAs($taker)->postJson("/api/p2p/trades/{$trade['id']}/confirm-payment")->assertOk();

        $this->actingAs($taker)->postJson("/api/p2p/trades/{$trade['id']}/release", [], ['Idempotency-Key' => 'release-wrong-actor'])
            ->assertStatus(403); // only the seller can release

        $this->actingAs($maker)->postJson("/api/p2p/trades/{$trade['id']}/release", [], ['Idempotency-Key' => 'release-1'])
            ->assertOk();

        $this->assertSame(800_000, $this->walletFor($maker, 'USDT')->fresh()->balance);
        $this->assertSame(200_000, $this->walletFor($taker, 'USDT')->fresh()->balance);
    }

    public function test_cancelling_a_pending_trade_refunds_escrow_and_reopens_offer_capacity(): void
    {
        $maker = $this->createUserWithWallets('maker2@example.com');
        $taker = $this->createUserWithWallets('taker2@example.com');
        $this->walletFor($maker, 'BTC')->update(['balance' => 1_000_000]);

        $offer = $this->actingAs($maker)->postJson('/api/p2p/offers', [
            'side' => 'sell', 'currency' => 'BTC', 'rate' => 95000000,
            'amount' => 1_000_000, 'min_order_amount' => 1000, 'max_order_amount' => 1_000_000,
        ])->assertCreated()->json();

        $trade = $this->actingAs($taker)->postJson("/api/p2p/offers/{$offer['id']}/trades", [
            'amount' => 300_000,
        ], ['Idempotency-Key' => 'trade-cancel'])->assertCreated()->json();

        $this->assertSame(700_000, $this->walletFor($maker, 'BTC')->fresh()->balance);

        $this->actingAs($taker)->postJson("/api/p2p/trades/{$trade['id']}/cancel")->assertOk();

        $this->assertSame(1_000_000, $this->walletFor($maker, 'BTC')->fresh()->balance);
    }

    public function test_trade_amount_outside_offer_range_is_rejected(): void
    {
        $maker = $this->createUserWithWallets('maker3@example.com');
        $taker = $this->createUserWithWallets('taker3@example.com');
        $this->walletFor($maker, 'BTC')->update(['balance' => 1_000_000]);

        $offer = $this->actingAs($maker)->postJson('/api/p2p/offers', [
            'side' => 'sell', 'currency' => 'BTC', 'rate' => 95000000,
            'amount' => 1_000_000, 'min_order_amount' => 100_000, 'max_order_amount' => 500_000,
        ])->assertCreated()->json();

        $this->actingAs($taker)->postJson("/api/p2p/offers/{$offer['id']}/trades", [
            'amount' => 50_000,
        ], ['Idempotency-Key' => 'trade-oob'])->assertStatus(422);
    }
}
