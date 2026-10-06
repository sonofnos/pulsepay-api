<?php

namespace App\Services;

use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class P2pTradeService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function initiateTrade(P2pOffer $offer, User $taker, int $amount): P2pTrade
    {
        return DB::transaction(function () use ($offer, $taker, $amount) {
            $offer = P2pOffer::where('id', $offer->id)->lockForUpdate()->first();

            if ($offer->status !== 'open') {
                throw new RuntimeException('Offer is not open.');
            }

            if ($offer->maker_id === $taker->id) {
                throw new RuntimeException('Cannot trade against your own offer.');
            }

            if ($amount < $offer->min_order_amount || $amount > $offer->max_order_amount || $amount > $offer->remaining_amount) {
                throw new RuntimeException('Trade amount is outside the offer\'s allowed range.');
            }

            [$sellerId, $buyerId] = $offer->side === 'sell'
                ? [$offer->maker_id, $taker->id]
                : [$taker->id, $offer->maker_id];

            $sellerWallet = Wallet::where('user_id', $sellerId)->where('currency', $offer->currency)->where('kind', 'user')->firstOrFail();
            $escrowWallet = $this->ledger->escrowWallet($offer->currency);

            $escrowTransactionId = $this->ledger->post(
                $sellerWallet->id,
                $escrowWallet->id,
                $amount,
                'p2p_escrow_lock',
                ['offer_id' => $offer->id, 'seller_id' => $sellerId, 'buyer_id' => $buyerId],
            );

            $offer->decrement('remaining_amount', $amount);
            if ($offer->remaining_amount === 0) {
                $offer->update(['status' => 'closed']);
            }

            $fiatAmount = round(($amount / 100) * $offer->rate, 2);

            return P2pTrade::create([
                'offer_id' => $offer->id,
                'maker_id' => $offer->maker_id,
                'taker_id' => $taker->id,
                'amount' => $amount,
                'fiat_amount' => $fiatAmount,
                'status' => 'pending_payment',
                'escrow_transaction_id' => $escrowTransactionId,
            ]);
        });
    }

    public function confirmFiatPaid(P2pTrade $trade, User $actor): P2pTrade
    {
        $buyerId = $this->buyerId($trade);
        abort_if($actor->id !== $buyerId, 403, 'Only the buyer can confirm payment was sent.');
        abort_if($trade->status !== 'pending_payment', 422, 'Trade is not awaiting payment confirmation.');

        $trade->update(['status' => 'paid_confirmed', 'paid_confirmed_at' => now()]);

        return $trade;
    }

    public function release(P2pTrade $trade, User $actor): P2pTrade
    {
        $sellerId = $this->sellerId($trade);
        abort_if($actor->id !== $sellerId, 403, 'Only the seller can release escrowed funds.');
        abort_if($trade->status !== 'paid_confirmed', 422, 'Trade must have payment confirmed before release.');

        $buyerWallet = Wallet::where('user_id', $this->buyerId($trade))->where('currency', $trade->offer->currency)->where('kind', 'user')->firstOrFail();
        $escrowWallet = $this->ledger->escrowWallet($trade->offer->currency);

        $this->ledger->post(
            $escrowWallet->id,
            $buyerWallet->id,
            $trade->amount,
            'p2p_escrow_release',
            ['trade_id' => $trade->id],
            allowOverdraft: true,
        );

        $trade->update(['status' => 'released', 'completed_at' => now()]);

        return $trade;
    }

    public function cancel(P2pTrade $trade, User $actor): P2pTrade
    {
        abort_if(! in_array($actor->id, [$trade->maker_id, $trade->taker_id], true), 403);
        abort_if($trade->status !== 'pending_payment', 422, 'Only a trade awaiting payment can be cancelled.');

        return DB::transaction(function () use ($trade) {
            $sellerWallet = Wallet::where('user_id', $this->sellerId($trade))->where('currency', $trade->offer->currency)->where('kind', 'user')->firstOrFail();
            $escrowWallet = $this->ledger->escrowWallet($trade->offer->currency);

            $this->ledger->post(
                $escrowWallet->id,
                $sellerWallet->id,
                $trade->amount,
                'p2p_escrow_refund',
                ['trade_id' => $trade->id],
            );

            $offer = P2pOffer::where('id', $trade->offer_id)->lockForUpdate()->first();
            $offer->increment('remaining_amount', $trade->amount);
            if ($offer->status === 'closed') {
                $offer->update(['status' => 'open']);
            }

            $trade->update(['status' => 'cancelled']);

            return $trade;
        });
    }

    private function sellerId(P2pTrade $trade): int
    {
        return $trade->offer->side === 'sell' ? $trade->maker_id : $trade->taker_id;
    }

    private function buyerId(P2pTrade $trade): int
    {
        return $trade->offer->side === 'sell' ? $trade->taker_id : $trade->maker_id;
    }
}
