<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Динамические чекбоксы контент-сущностей: JSON-map значений (field => bool)
 * на 8 таблицах. Определения чекбоксов живут в admin_settings.entity_checkboxes,
 * значения проставляются в формах админки и синкаются в storefront через RabbitMQ.
 * NULL = чекбоксы не проставлялись; отсутствие ключа в map = false.
 */
return new class extends Migration
{
    private const TABLES = [
        'products',
        'categories',
        'merchant_product_groups',
        'stories',
        'brands',
        'merchant_banners',
        'merchant_sales',
        'shops',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasColumn($table, 'custom_checkboxes')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('custom_checkboxes')->nullable()
                        ->comment('Динамические чекбоксы: map field => bool (определения в admin_settings.entity_checkboxes)');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'custom_checkboxes')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('custom_checkboxes');
                });
            }
        }
    }
};
