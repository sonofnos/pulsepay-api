<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_provisions_zero_balance_wallets_for_every_supported_currency(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()->assertJsonStructure(['user', 'token']);

        $userId = $response->json('user.id');
        $wallets = Wallet::where('user_id', $userId)->get();

        $this->assertSame(['BTC', 'NGN', 'USDT'], $wallets->pluck('currency')->sort()->values()->all());
        $this->assertTrue($wallets->every(fn (Wallet $w) => $w->balance === 0));
    }

    public function test_login_succeeds_with_correct_credentials_and_fails_with_incorrect_ones(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $this->postJson('/api/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_protected_route_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/wallets')->assertStatus(401);
    }

    public function test_protected_route_returns_clean_401_even_without_an_accept_header(): void
    {
        // getJson() sets Accept: application/json for us, which was hiding a bug:
        // Laravel's default Authenticate middleware tries to redirect guests to
        // a named 'login' route when the request doesn't look like it expects
        // JSON, and this API has no such route, so it crashed with a 500.
        $this->get('/api/wallets')->assertStatus(401);
    }
}
