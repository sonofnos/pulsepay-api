<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Services\AuditLogger;
use App\Services\P2pTradeService;
use Illuminate\Http\Request;
use RuntimeException;

class P2pTradeController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return P2pTrade::where('maker_id', $userId)
            ->orWhere('taker_id', $userId)
            ->with('offer')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    public function store(Request $request, P2pOffer $offer, P2pTradeService $service, AuditLogger $auditLogger)
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        try {
            $trade = $service->initiateTrade($offer, $request->user(), $data['amount']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $auditLogger->log($request->user()->id, 'p2p_trade.initiate', 'P2pTrade', $trade->id, [], $request);

        return response()->json($trade, 201);
    }

    public function confirmPayment(Request $request, P2pTrade $trade, P2pTradeService $service, AuditLogger $auditLogger)
    {
        $trade = $service->confirmFiatPaid($trade, $request->user());

        $auditLogger->log($request->user()->id, 'p2p_trade.confirm_payment', 'P2pTrade', $trade->id, [], $request);

        return $trade;
    }

    public function release(Request $request, P2pTrade $trade, P2pTradeService $service, AuditLogger $auditLogger)
    {
        $trade = $service->release($trade, $request->user());

        $auditLogger->log($request->user()->id, 'p2p_trade.release', 'P2pTrade', $trade->id, [], $request);

        return $trade;
    }

    public function cancel(Request $request, P2pTrade $trade, P2pTradeService $service, AuditLogger $auditLogger)
    {
        $trade = $service->cancel($trade, $request->user());

        $auditLogger->log($request->user()->id, 'p2p_trade.cancel', 'P2pTrade', $trade->id, [], $request);

        return $trade;
    }
}
