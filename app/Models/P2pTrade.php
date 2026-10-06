<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2pTrade extends Model
{
    protected $fillable = [
        'offer_id', 'maker_id', 'taker_id', 'amount', 'fiat_amount',
        'status', 'escrow_transaction_id', 'paid_confirmed_at', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'fiat_amount' => 'decimal:2',
        'paid_confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(P2pOffer::class, 'offer_id');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taker_id');
    }
}
