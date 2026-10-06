<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\P2pOffer;
use Illuminate\Http\Request;

class P2pOfferController extends Controller
{
    public function index(Request $request)
    {
        $query = P2pOffer::query()->where('status', 'open')->with('maker:id,name');

        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }

        if ($side = $request->query('side')) {
            $query->where('side', $side);
        }

        return $query->orderByDesc('created_at')->paginate(25);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'side' => ['required', 'in:buy,sell'],
            'currency' => ['required', 'string', 'in:BTC,USDT'],
            'fiat_currency' => ['sometimes', 'string', 'in:NGN'],
            'rate' => ['required', 'numeric', 'min:0.01'],
            'amount' => ['required', 'integer', 'min:1'],
            'min_order_amount' => ['required', 'integer', 'min:1', 'lte:amount'],
            'max_order_amount' => ['required', 'integer', 'min:1', 'gte:min_order_amount', 'lte:amount'],
        ]);

        $offer = $request->user()->p2pOffers()->create($data + [
            'fiat_currency' => $data['fiat_currency'] ?? 'NGN',
            'remaining_amount' => $data['amount'],
            'status' => 'open',
        ]);

        return response()->json($offer, 201);
    }

    public function cancel(Request $request, P2pOffer $offer)
    {
        abort_if($offer->maker_id !== $request->user()->id, 403);
        abort_if($offer->status !== 'open', 422, 'Only an open offer can be cancelled.');

        $offer->update(['status' => 'cancelled']);

        return $offer;
    }
}
