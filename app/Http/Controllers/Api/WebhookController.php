<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function cryptoDeposit(Request $request, LedgerService $ledger, AuditLogger $auditLogger)
    {
        $signature = $request->header('X-Signature', '');
        $secret = config('services.mock_crypto_processor.webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $signatureValid = hash_equals($expected, $signature);

        $payload = $request->validate([
            'event_id' => ['required', 'string'],
            'user_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'in:BTC,USDT'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        if (! $signatureValid) {
            WebhookEvent::create([
                'provider' => 'mock_crypto_processor',
                'event_id' => $payload['event_id'],
                'signature_valid' => false,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            Log::warning('Rejected crypto deposit webhook with invalid signature.', ['event_id' => $payload['event_id']]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // createOrFirst catches the unique-constraint race on (provider, event_id)
        // instead of throwing when two copies of the same delivery land together.
        $event = WebhookEvent::createOrFirst(
            ['provider' => 'mock_crypto_processor', 'event_id' => $payload['event_id']],
            ['signature_valid' => true, 'payload' => $payload, 'created_at' => now()],
        );

        // Lock the row for the deposit too, otherwise both copies could still
        // see processed_at as null and both post it.
        $result = DB::transaction(function () use ($event, $payload, $ledger) {
            $locked = WebhookEvent::where('id', $event->id)->lockForUpdate()->first();

            if ($locked->processed_at !== null) {
                return ['already_processed' => true, 'transaction_id' => null];
            }

            $user = User::findOrFail($payload['user_id']);
            $wallet = $user->wallets()->where('currency', $payload['currency'])->where('kind', 'user')->firstOrFail();

            $transactionId = $ledger->deposit($wallet, $payload['amount'], 'deposit', ['webhook_event_id' => $locked->id]);

            $locked->update(['processed_at' => now()]);

            return ['already_processed' => false, 'transaction_id' => $transactionId, 'wallet_id' => $wallet->id, 'user_id' => $user->id];
        });

        if ($result['already_processed']) {
            return response()->json(['message' => 'Event already processed.', 'event_id' => $event->event_id]);
        }

        $auditLogger->log($result['user_id'], 'webhook.crypto_deposit', 'Wallet', $result['wallet_id'], [
            'transaction_id' => $result['transaction_id'],
            'amount' => $payload['amount'],
        ], $request);

        return response()->json(['message' => 'Processed.', 'transaction_id' => $result['transaction_id']]);
    }
}
