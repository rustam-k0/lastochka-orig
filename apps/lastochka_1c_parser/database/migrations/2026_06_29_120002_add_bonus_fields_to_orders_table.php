<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'bonus_earned')) {
                $table->integer('bonus_earned')->default(0)->after('discount');
            }
            if (!Schema::hasColumn('orders', 'bonus_spent')) {
                $table->integer('bonus_spent')->default(0)->after('bonus_earned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['bonus_earned', 'bonus_spent'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
