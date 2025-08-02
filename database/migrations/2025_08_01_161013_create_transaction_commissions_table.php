<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('operator_id');
            $table->uuid('entreprise_id');
            
            // Montants de base
            $table->decimal('transaction_amount', 15, 2); // Montant de la transaction
            $table->string('currency_code', 3);
            
            // Commissions opérateur
            $table->decimal('operator_commission_rate', 5, 2); // Taux en %
            $table->decimal('operator_commission_amount', 15, 2); // Montant calculé 
            
            // Commissions internes
            $table->decimal('internal_commission_rate', 5, 2); // Taux en %
            $table->decimal('internal_commission_amount', 15, 2); // Montant calculé 
            
            // Totaux
            $table->decimal('total_commission', 15, 2); // operator + internal
            $table->decimal('net_amount', 15, 2); // transaction_amount - total_commission
            
            // Méta-données
            $table->enum('transaction_type', ['deposit', 'withdrawal']); 
            $table->text('calculation_details')->nullable(); // Détails du calcul
            $table->uuid('calculated_by')->nullable(); // Qui a calculé (user/system)
            
            // Timestamps
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();
            
            // Index pour performance
            $table->index(['transaction_id'], 'idx_transaction_commission');
            $table->index(['operator_id', 'calculated_at'], 'idx_operator_date');
            $table->index(['entreprise_id', 'calculated_at'], 'idx_entreprise_date');
            $table->index(['currency_code', 'calculated_at'], 'idx_currency_date');
            
            // Clés étrangères
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            
            // Contrainte d'unicité : une seule commission par transaction
            $table->unique('transaction_id', 'unique_transaction_commission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_commissions');
    }
};
