<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Признак маркируемого товара (Честный знак, MID-266). Источник — курьерка
 * (product.updated из admin_back, куда признак приходит из 1С).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_marked')) {
                $table->boolean('is_marked')->default(false)->after('adult_only')->comment('Маркируемый товар (Честный знак): при сборке требуется скан кода маркировки');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_marked')) {
                $table->dropColumn('is_marked');
            }
        });
    }
};
