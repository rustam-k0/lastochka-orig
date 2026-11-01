<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить поле show_on_search в таблицу product_groups.
     */
    public function up(): void
    {
        if (Schema::hasColumn('product_groups', 'show_on_search')) {
            return;
        }

        Schema::table('product_groups', function (Blueprint $table) {
            $table->boolean('show_on_search')->default(false)->after('show_on_home');
        });
    }

    /**
     * Откатить изменения.
     */
    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('show_on_search');
        });
    }
};
