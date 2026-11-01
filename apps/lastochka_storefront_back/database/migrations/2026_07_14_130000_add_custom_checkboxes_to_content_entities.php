<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'products',
        'categories',
        'product_groups',
        'stories',
        'brands',
        'banners',
        'promotions',
        'stores',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'custom_checkboxes')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('custom_checkboxes')->nullable()
                        ->comment('Динамические чекбоксы: map field => bool, приходит из admin_back через RabbitMQ');
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
