<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use CreatesAuthenticatedUsers, RefreshDatabase;

    private function signed(array $payload): array
    {
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, config('services.mock_crypto_processor.webhook_secret'));

        return [$body, $signature];
    }

    public function test_valid_signature_credits_the_wallet_exactly_once(): void
    {
        $user = $this->createUserWithWallets('depositor@example.com');
        $payload = ['event_id' => 'evt_123', 'user_id' => $user->id, 'currency' => 'BTC', 'amount' => 250000];
        [$body, $signature] = $this->signed($payload);

        $response = $this->call('POST', '/api/webhooks/crypto-deposit', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Signature' => $signature,
        ], $body);

        $response->assertOk();
        $this->assertSame(250000, $this->walletFor($user, 'BTC')->fresh()->balance);
    }

    public function test_replayed_event_id_is_not_applied_twice(): void
    {
        $user = $this->createUserWithWallets('depositor2@example.com');
        $payload = ['event_id' => 'evt_replay', 'user_id' => $user->id, 'currency' => 'BTC', 'amount' => 100000];
        [$body, $signature] = $this->signed($payload);

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Signature' => $signature];

        $this->call('POST', '/api/webhooks/crypto-deposit', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/webhooks/crypto-deposit', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(100000, $this->walletFor($user, 'BTC')->fresh()->balance);
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_replay')->count());
    }

    public function test_invalid_signature_is_rejected_and_wallet_is_untouched(): void
    {
        $user = $this->createUserWithWallets('depositor3@example.com');
        $payload = ['event_id' => 'evt_bad', 'user_id' => $user->id, 'currency' => 'BTC', 'amount' => 999999];
        $body = json_encode($payload);

        $response = $this->call('POST', '/api/webhooks/crypto-deposit', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Signature' => 'not-the-real-signature',
        ], $body);

        $response->assertStatus(401);
        $this->assertSame(0, $this->walletFor($user, 'BTC')->fresh()->balance);
    }
}
