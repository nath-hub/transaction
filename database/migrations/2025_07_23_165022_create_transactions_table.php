<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('entreprise_id');
            $table->uuid('wallet_id');
            $table->uuid('operator_id');
            $table->uuid('user_id')->nullable();

            $table->enum('transaction_type', ['deposit', 'withdrawal']);
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3);

            // Commissions
            $table->decimal('operator_commission', 15, 2)->default(0.00); // -- Commission opérateur (1%)
            $table->decimal('internal_commission', 15, 2)->default(0.00); //-- Commission interne
            $table->decimal('net_amount', 15, 2); // montant net après commissions

            // Statuts
            $table->enum('status', ['FAILED', 'CANCELLED', 'EXPIRED', 'SUCCESSFULL', 'PENDING'])->default('PENDING');
            $table->string('operator_status', 50)->nullable(); //-- Statut retourné par l'opérateur
            $table->string('operator_transaction_id', 100)->nullable(); //-- ID transaction chez l'opérateur

            // Infos client
            $table->string('customer_phone', 20);
            $table->string('customer_name', 100)->nullable();

             // Nombre de tentatives
            $table->integer('webhook_attempts')->default(0);
            
            // Dernière tentative
            $table->string('payToken')->nullable();
            
            // Prochaine tentative (pour le retry)
            $table->string('access_token')->nullable();

            // Réponse du webhook (pour debug)
            $table->json('webhook_response')->nullable();
            // URL de webhook fournie par le client
            $table->string('webhook_url')->nullable();

            // Statut du webhook
            $table->enum('webhook_status', ['pending', 'sent', 'failed', 'disabled'])
                ->default('pending');

            // Tracabilité
            $table->timestamp('initiated_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->string('api_key_used', 100)->nullable(); //-- Clé API utilisée
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Métadonnées
            $table->json('metadata')->nullable();
            $table->string('failure_reason', 500)->nullable();

            // Timestamps
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('company_wallets')->onDelete('cascade');

            // Indexes
            $table->index('created_at', 'idx_transaction_date');
            $table->index(['entreprise_id', 'status'], 'idx_entreprise_status');
            $table->index('customer_phone', 'idx_customer_phone');
            $table->index('operator_transaction_id', 'idx_operator_transaction');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
