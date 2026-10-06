<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            // Nullable: system liquidity wallets (deposits/withdrawals/escrow) have no owning user.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('currency', 10); // NGN, BTC, USDT, ...
            $table->enum('kind', ['user', 'system_liquidity', 'escrow'])->default('user');
            // All balances are bigint minor units (kobo / satoshis / cents), never floats.
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency', 'kind']);
            $table->index(['currency', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
