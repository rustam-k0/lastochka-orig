<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Зеркало бонусного баланса клиента. Источник истины — storefront_back,
 * значения приезжают событием customer.updated. Локально не изменяются.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'bonus_balance')) {
                $table->unsignedInteger('bonus_balance')->default(0);
            }
            if (!Schema::hasColumn('clients', 'bonus_reserved')) {
                $table->unsignedInteger('bonus_reserved')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['bonus_balance', 'bonus_reserved'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
