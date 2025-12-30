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
        Schema::table('sale_items', function (Blueprint $table) {
            // Store cost and profit per item
            $table->decimal('cost_price', 12, 4)->default(0)->after('unit_price'); // Cost per unit at time of sale
            $table->decimal('cost_total', 12, 2)->default(0)->after('cost_price');  // Total cost for this line
            $table->decimal('profit_amount', 12, 2)->default(0)->after('line_total'); // Profit for this line
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'cost_total', 'profit_amount']);
        });
    }
};
