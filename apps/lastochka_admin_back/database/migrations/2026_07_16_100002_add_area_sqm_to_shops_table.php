<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'area_sqm')) {
                $table->decimal('area_sqm', 8, 2)->nullable()
                    ->comment('Площадь магазина, м²');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'area_sqm')) {
                $table->dropColumn('area_sqm');
            }
        });
    }
};
