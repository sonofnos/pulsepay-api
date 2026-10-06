<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            // Groups the debit+credit pair (and any fee legs) that make up one posting.
            $table->uuid('transaction_id');
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['debit', 'credit']);
            $table->bigInteger('amount'); // always positive; direction carries the sign
            $table->bigInteger('balance_after');
            $table->enum('type', [
                'deposit', 'withdrawal', 'transfer', 'p2p_escrow_lock',
                'p2p_escrow_release', 'p2p_escrow_refund', 'bill_payment', 'bill_payment_reversal', 'fee',
            ]);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
