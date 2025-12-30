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
        Schema::table('sales', function (Blueprint $table) {
            // Add profit tracking fields
            $table->decimal('total_cost', 12, 2)->default(0)->after('total_amount');
            $table->decimal('profit_amount', 12, 2)->default(0)->after('total_cost');
            $table->decimal('profit_margin', 5, 2)->default(0)->after('profit_amount'); // Percentage

            // Add index for profit queries
            $table->index(['company_id', 'profit_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'profit_amount']);
            $table->dropColumn(['total_cost', 'profit_amount', 'profit_margin']);
        });
    }
};
