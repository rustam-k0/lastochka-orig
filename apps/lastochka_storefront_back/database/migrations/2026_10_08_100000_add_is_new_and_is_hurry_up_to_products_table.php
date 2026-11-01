<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галочки товара «Новинка» и «Успей купить» (автоматические товарные группы
 * is_new / is_hurry_up). Источник — курьерка (product.updated из admin_back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'is_new')) {
                $table->boolean('is_new')->default(false)->comment('Новинка: товар попадает в автогруппу «Новинки»');
                $table->index('is_new');
            }
            if (! Schema::hasColumn('products', 'is_hurry_up')) {
                $table->boolean('is_hurry_up')->default(false)->comment('Успей купить: товар попадает в автогруппу «Успей купить»');
                $table->index('is_hurry_up');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_new')) {
                $table->dropIndex(['is_new']);
                $table->dropColumn('is_new');
            }
            if (Schema::hasColumn('products', 'is_hurry_up')) {
                $table->dropIndex(['is_hurry_up']);
                $table->dropColumn('is_hurry_up');
            }
        });
    }
};
