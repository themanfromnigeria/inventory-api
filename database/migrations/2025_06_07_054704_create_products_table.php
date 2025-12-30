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
       Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->ulid('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('image_url')->nullable();

            // Pricing
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('selling_price', 10, 2)->default(0);

            // Inventory
            $table->decimal('stock_quantity', 15, 6)->default(0);
            $table->decimal('minimum_stock', 15, 6)->default(0);

            // Product settings
            $table->boolean('track_stock')->default(true);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');

            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'stock_quantity', 'minimum_stock']);
            $table->unique(['company_id', 'sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
