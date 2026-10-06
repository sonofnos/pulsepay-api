<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Domain\InsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->wallets()->get(['id', 'currency', 'balance', 'created_at']);
    }

    public function transactions(Request $request, Wallet $wallet)
    {
        abort_if($wallet->user_id !== $request->user()->id, 403);

        return $wallet->entries()->orderByDesc('created_at')->paginate(25);
    }

    public function transfer(Request $request, LedgerService $ledger, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'recipient_email' => ['required', 'email'],
            'currency' => ['required', 'string', 'in:NGN,BTC,USDT'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $sender = $request->user();
        $recipient = User::where('email', $data['recipient_email'])->firstOrFail();

        abort_if($recipient->id === $sender->id, 422, 'Cannot transfer to yourself.');

        $fromWallet = $sender->wallets()->where('currency', $data['currency'])->where('kind', 'user')->firstOrFail();
        $toWallet = $recipient->wallets()->where('currency', $data['currency'])->where('kind', 'user')->firstOrFail();

        try {
            $transactionId = $ledger->post(
                $fromWallet->id,
                $toWallet->id,
                $data['amount'],
                'transfer',
                ['recipient_id' => $recipient->id, 'sender_id' => $sender->id],
            );
        } catch (InsufficientFundsException $e) {
            return response()->json(['message' => 'Insufficient funds.'], 422);
        }

        $auditLogger->log($sender->id, 'wallet.transfer', 'LedgerEntry', null, [
            'transaction_id' => $transactionId,
            'to_user_id' => $recipient->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
        ], $request);

        return response()->json([
            'transaction_id' => $transactionId,
            'balance' => $fromWallet->refresh()->balance,
        ]);
    }
}
