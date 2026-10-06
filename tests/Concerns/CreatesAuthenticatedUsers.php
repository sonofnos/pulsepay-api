<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;

trait CreatesAuthenticatedUsers
{
    private const SUPPORTED_WALLETS = ['NGN', 'BTC', 'USDT'];

    protected function createUserWithWallets(?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'),
        ]);

        foreach (self::SUPPORTED_WALLETS as $currency) {
            Wallet::create([
                'user_id' => $user->id,
                'currency' => $currency,
                'kind' => 'user',
                'balance' => 0,
            ]);
        }

        return $user;
    }

    protected function walletFor(User $user, string $currency): Wallet
    {
        return Wallet::where('user_id', $user->id)->where('currency', $currency)->where('kind', 'user')->firstOrFail();
    }
}
