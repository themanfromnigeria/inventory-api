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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_purchase_cost', 10, 2)->nullable()->after('cost_price');
            $table->date('last_purchase_date')->nullable()->after('last_purchase_cost');
            $table->enum('cost_method', ['manual', 'last_purchase'])->default('manual')->after('last_purchase_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['last_purchase_cost', 'last_purchase_date', 'cost_method']);
        });
    }
};
