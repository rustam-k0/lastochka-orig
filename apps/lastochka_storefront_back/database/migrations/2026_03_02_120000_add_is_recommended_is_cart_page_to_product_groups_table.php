<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить поля is_recommended и is_cart_page в таблицу product_groups.
     */
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->boolean('is_recommended')->default(false)->after('show_on_home');
            $table->boolean('is_cart_page')->default(false)->after('is_recommended');
        });
    }

    /**
     * Откатить изменения.
     */
    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn(['is_recommended', 'is_cart_page']);
        });
    }
};