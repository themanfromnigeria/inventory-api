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
        Schema::table('stock_movements', function (Blueprint $table) {
            // Support fractional quantities in movements
            $table->decimal('quantity', 15, 6)->change();
            $table->decimal('stock_before', 15, 6)->change();
            $table->decimal('stock_after', 15, 6)->change();

            // Reference to unit used in this movement
            $table->ulid('unit_id')->nullable()->after('notes');

            $table->foreign('unit_id')->references('id')->on('units')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
