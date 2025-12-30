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
       Schema::create('units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->string('name'); // 'Kilogram', 'Liter', 'Pieces'
            $table->string('symbol'); // 'kg', 'L', 'pcs'
            $table->string('type'); // 'weight', 'volume', 'count', 'length', 'custom'
            $table->boolean('allow_decimals')->default(true); // Can use 10.5kg?
            $table->integer('decimal_places')->default(2); // Max decimal places
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'symbol']);
            $table->index(['company_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
