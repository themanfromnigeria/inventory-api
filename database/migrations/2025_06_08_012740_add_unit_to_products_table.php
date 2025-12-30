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
            $table->ulid('unit_id')->nullable()->after('track_stock');

            $table->foreign('unit_id')->references('id')->on('units')->onDelete('set null');
            $table->index(['company_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['company_id', 'unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
