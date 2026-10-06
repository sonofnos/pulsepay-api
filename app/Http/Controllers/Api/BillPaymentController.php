<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Domain\InsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Services\Vtu\VtuProvider;
use App\Services\Vtu\VtuProviderException;
use Illuminate\Http\Request;

class BillPaymentController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->billPayments()->orderByDesc('created_at')->paginate(25);
    }

    public function store(Request $request, LedgerService $ledger, VtuProvider $provider, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'type' => ['required', 'in:airtime,data,electricity'],
            'recipient' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $wallet = $user->wallets()->where('currency', 'NGN')->where('kind', 'user')->firstOrFail();

        $billPayment = BillPayment::create([
            'user_id' => $user->id,
            'provider' => 'mock_vtu',
            'type' => $data['type'],
            'recipient' => $data['recipient'],
            'amount' => $data['amount'],
            'status' => 'pending',
        ]);

        try {
            $transactionId = $ledger->withdraw($wallet, $data['amount'], 'bill_payment', ['bill_payment_id' => $billPayment->id]);
        } catch (InsufficientFundsException $e) {
            $billPayment->update(['status' => 'failed']);

            return response()->json(['message' => 'Insufficient funds.'], 422);
        }

        $billPayment->update(['ledger_transaction_id' => $transactionId]);

        try {
            $result = $provider->purchase($data['type'], $data['recipient'], $data['amount']);
        } catch (VtuProviderException $e) {
            $ledger->reverse($transactionId, 'bill_payment_reversal', ['bill_payment_id' => $billPayment->id]);
            $billPayment->update(['status' => 'reversed']);

            $auditLogger->log($user->id, 'bill_payment.reversed', 'BillPayment', $billPayment->id, [
                'reason' => $e->getMessage(),
            ], $request);

            return response()->json(['message' => 'Provider declined the purchase; your wallet has been refunded.'], 422);
        }

        $billPayment->update(['status' => 'successful', 'provider_reference' => $result->providerReference]);

        $auditLogger->log($user->id, 'bill_payment.successful', 'BillPayment', $billPayment->id, [
            'transaction_id' => $transactionId,
        ], $request);

        return response()->json($billPayment, 201);
    }
}
