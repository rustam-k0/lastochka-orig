<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assembly_zones', function (Blueprint $table) {
            // Цветовое обозначение зоны (HEX, для индикации в приложении сборщика).
            if (!Schema::hasColumn('assembly_zones', 'color')) {
                $table->string('color', 16)->nullable()->after('title');
            }

            // Активность зоны (вкл/выкл). По умолчанию активна.
            if (!Schema::hasColumn('assembly_zones', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('color');
            }

            // Описание / комментарий к зоне.
            if (!Schema::hasColumn('assembly_zones', 'description')) {
                $table->text('description')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assembly_zones', function (Blueprint $table) {
            foreach (['color', 'is_active', 'description'] as $column) {
                if (Schema::hasColumn('assembly_zones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
