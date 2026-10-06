<?php

namespace App\Services;

use App\Exceptions\Domain\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Double-entry posting engine. Everything that moves money (transfers,
// deposits, withdrawals, P2P escrow, bill payments) goes through post().
class LedgerService
{
    // Locks both wallets in ascending id order (not fromWallet/toWallet order)
    // so concurrent transfers sharing a wallet can't deadlock each other.
    public function post(
        int $fromWalletId,
        int $toWalletId,
        int $amount,
        string $type,
        array $metadata = [],
        bool $allowOverdraft = false,
    ): string {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Ledger amount must be positive.');
        }

        $transactionId = (string) Str::uuid();

        DB::transaction(function () use ($fromWalletId, $toWalletId, $amount, $type, $metadata, $allowOverdraft, $transactionId) {
            $ids = [$fromWalletId, $toWalletId];
            sort($ids);

            $locked = Wallet::whereIn('id', $ids)
                ->lockForUpdate()
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $from = $locked->get($fromWalletId);
            $to = $locked->get($toWalletId);

            if (! $from || ! $to) {
                throw new \RuntimeException('One or both wallets do not exist.');
            }

            if (! $allowOverdraft && $from->balance < $amount) {
                throw new InsufficientFundsException($fromWalletId);
            }

            $from->decrement('balance', $amount);
            $to->increment('balance', $amount);
            $from->refresh();
            $to->refresh();

            $now = now();

            LedgerEntry::create([
                'transaction_id' => $transactionId,
                'wallet_id' => $from->id,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $from->balance,
                'type' => $type,
                'metadata' => $metadata,
                'created_at' => $now,
            ]);

            LedgerEntry::create([
                'transaction_id' => $transactionId,
                'wallet_id' => $to->id,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $to->balance,
                'type' => $type,
                'metadata' => $metadata,
                'created_at' => $now,
            ]);
        });

        return $transactionId;
    }

    public function deposit(Wallet $userWallet, int $amount, string $type = 'deposit', array $metadata = []): string
    {
        $system = $this->systemLiquidityWallet($userWallet->currency);

        return $this->post($system->id, $userWallet->id, $amount, $type, $metadata, allowOverdraft: true);
    }

    public function withdraw(Wallet $userWallet, int $amount, string $type = 'withdrawal', array $metadata = []): string
    {
        $system = $this->systemLiquidityWallet($userWallet->currency);

        return $this->post($userWallet->id, $system->id, $amount, $type, $metadata);
    }

    public function reverse(string $originalTransactionId, string $type, array $metadata = []): string
    {
        $entries = LedgerEntry::where('transaction_id', $originalTransactionId)->get();
        $debit = $entries->firstWhere('direction', 'debit');
        $credit = $entries->firstWhere('direction', 'credit');

        if (! $debit || ! $credit) {
            throw new \RuntimeException("Cannot reverse transaction {$originalTransactionId}: original posting not found.");
        }

        return $this->post($credit->wallet_id, $debit->wallet_id, $debit->amount, $type, $metadata + ['reverses' => $originalTransactionId], allowOverdraft: true);
    }

    public function systemLiquidityWallet(string $currency): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => null, 'currency' => $currency, 'kind' => 'system_liquidity'],
            ['balance' => 0],
        );
    }

    public function escrowWallet(string $currency): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => null, 'currency' => $currency, 'kind' => 'escrow'],
            ['balance' => 0],
        );
    }

    public function reconstructBalance(Wallet $wallet): int
    {
        $credits = (int) LedgerEntry::where('wallet_id', $wallet->id)->where('direction', 'credit')->sum('amount');
        $debits = (int) LedgerEntry::where('wallet_id', $wallet->id)->where('direction', 'debit')->sum('amount');

        return $credits - $debits;
    }
}
