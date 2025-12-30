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
        Schema::create('sale_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->ulid('sale_id');
            $table->ulid('product_id');
            $table->ulid('unit_id')->nullable();

            // Product details at time of sale (for history)
            $table->string('product_name'); // Product name at time of sale
            $table->string('product_sku')->nullable();

            // Quantity and pricing
            $table->decimal('quantity', 15, 6);
            $table->decimal('unit_price', 12, 4); // Price per unit at time of sale
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 12, 2); // (quantity * unit_price) - discount

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('unit_id')->references('id')->on('units')->onDelete('set null');

            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
