<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50); // e.g. "mock_vtu"
            $table->enum('type', ['airtime', 'data', 'electricity']);
            $table->string('recipient'); // phone number / meter number
            $table->bigInteger('amount'); // NGN minor units
            $table->enum('status', ['pending', 'successful', 'failed', 'reversed'])->default('pending');
            $table->string('provider_reference')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
