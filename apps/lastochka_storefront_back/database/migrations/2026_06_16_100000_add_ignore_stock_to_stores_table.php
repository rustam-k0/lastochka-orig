<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Зеркало shops.ignore_stock из курьерки: магазин не учитывает остатки,
            // товары доступны к поиску/просмотру/покупке независимо от quantity.
            $table->boolean('ignore_stock')->default(false)->after('is_b2b');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('ignore_stock');
        });
    }
};
