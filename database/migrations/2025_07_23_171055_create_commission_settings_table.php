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
        Schema::create('commission_settings', function (Blueprint $table) {
           $table->uuid('id')->primary();
            $table->uuid('entreprise_id'); // Clé étrangère vers entreprise
            $table->uuid('country_id'); // Clé étrangère vers countries
            $table->uuid('operator_id'); // Clé étrangère vers operators

            $table->enum('transaction_type', ['deposit', 'withdrawal']);
            $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('commission_value', 8, 4); // Pourcentage ou montant fixe

            $table->decimal('min_amount', 15, 2)->default(0.00);
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);

            $table->uuid('created_by')->nullable(); // ID de l'administrateur
            $table->timestamps(); // created_at, updated_at

          
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_settings');
    }
};
