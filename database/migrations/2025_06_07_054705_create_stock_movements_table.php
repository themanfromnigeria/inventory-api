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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->ulid('product_id');
            $table->ulid('user_id');

            $table->enum('type', ['in', 'out', 'adjustment']); // Stock movement type
            $table->integer('quantity'); // Positive for in, negative for out
            $table->integer('stock_before'); // Stock level before this movement
            $table->integer('stock_after'); // Stock level after this movement

            $table->string('reference_type')->nullable(); // 'sale', 'purchase', 'adjustment', etc.
            $table->ulid('reference_id')->nullable(); // ID of related record
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['company_id', 'product_id', 'created_at']);
            $table->index(['company_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
