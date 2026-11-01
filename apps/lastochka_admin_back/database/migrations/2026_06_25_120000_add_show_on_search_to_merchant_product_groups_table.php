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
        if (Schema::hasColumn('merchant_product_groups', 'show_on_search')) {
            return;
        }

        Schema::table('merchant_product_groups', function (Blueprint $table) {
            $table->boolean('show_on_search')->default(false)->comment('Выводить на странице поиска');

            $table->index('show_on_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_product_groups', function (Blueprint $table) {
            $table->dropIndex(['show_on_search']);
            $table->dropColumn('show_on_search');
        });
    }
};
