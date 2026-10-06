<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p2p_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('p2p_offers')->cascadeOnDelete();
            $table->foreignId('maker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('taker_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('amount'); // crypto minor units
            $table->decimal('fiat_amount', 18, 2);
            // pending_payment: crypto escrowed, waiting for taker to mark fiat as paid.
            // paid_confirmed: taker says fiat sent; awaiting maker release.
            // released / cancelled / disputed are terminal-ish states.
            $table->enum('status', [
                'pending_payment', 'paid_confirmed', 'released', 'cancelled', 'disputed',
            ])->default('pending_payment');
            $table->uuid('escrow_transaction_id')->nullable();
            $table->timestamp('paid_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['maker_id', 'status']);
            $table->index(['taker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p2p_trades');
    }
};
