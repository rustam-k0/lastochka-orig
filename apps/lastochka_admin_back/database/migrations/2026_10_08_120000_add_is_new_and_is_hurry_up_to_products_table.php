<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_new')) {
                $table->boolean('is_new')->default(false)->comment('Новинка: наполняет автоматическую товарную группу fill_type=is_new');
            }

            if (!Schema::hasColumn('products', 'is_hurry_up')) {
                $table->boolean('is_hurry_up')->default(false)->comment('Успей купить: наполняет автоматическую товарную группу fill_type=is_hurry_up');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_new')) {
                $table->dropColumn('is_new');
            }

            if (Schema::hasColumn('products', 'is_hurry_up')) {
                $table->dropColumn('is_hurry_up');
            }
        });
    }
};
