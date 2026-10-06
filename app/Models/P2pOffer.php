<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2pOffer extends Model
{
    protected $fillable = [
        'maker_id', 'side', 'currency', 'fiat_currency', 'rate',
        'amount', 'remaining_amount', 'min_order_amount', 'max_order_amount', 'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'amount' => 'integer',
        'remaining_amount' => 'integer',
        'min_order_amount' => 'integer',
        'max_order_amount' => 'integer',
    ];

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'offer_id');
    }
}
