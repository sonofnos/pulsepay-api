<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'event_id', 'signature_valid', 'payload', 'processed_at', 'created_at'];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
