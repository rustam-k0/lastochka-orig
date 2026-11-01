<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоп-листы товаров per shop (T3): флаги + даты добавления прямо на строке товара.
 * «hidden» — товар скрыт на витрине магазина; «pickup_only» — в магазине только
 * самовывоз. Дата добавления в стоп-лист хранится в соответствующем *_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only');
            }
            if (!Schema::hasColumn('products', 'stop_list_hidden_at')) {
                $table->timestamp('stop_list_hidden_at')->nullable()->after('stop_list_hidden');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden_at');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only_at')) {
                $table->timestamp('stop_list_pickup_only_at')->nullable()->after('stop_list_pickup_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'stop_list_hidden',
                'stop_list_hidden_at',
                'stop_list_pickup_only',
                'stop_list_pickup_only_at',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
