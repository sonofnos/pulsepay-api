<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p2p_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maker_id')->constrained('users')->cascadeOnDelete();
            $table->enum('side', ['buy', 'sell']); // maker wants to buy or sell `currency` for `fiat_currency`
            $table->string('currency', 10); // e.g. USDT, BTC
            $table->string('fiat_currency', 10)->default('NGN');
            $table->decimal('rate', 18, 2); // fiat per 1 unit of currency
            $table->bigInteger('amount'); // total crypto minor units offered
            $table->bigInteger('remaining_amount');
            $table->bigInteger('min_order_amount');
            $table->bigInteger('max_order_amount');
            $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
            $table->timestamps();

            $table->index(['currency', 'side', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p2p_offers');
    }
};
