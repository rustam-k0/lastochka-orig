<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('quantity_step')->default(1)->after('unit_type')
                ->comment('Шаг количества при покупке (например, 500 для 500 грамм)');
            $table->string('measurement_unit', 20)->default('piece')->after('quantity_step')
                ->comment('Единица измерения: piece, gram, kilogram, liter, milliliter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['quantity_step', 'measurement_unit']);
        });
    }
};
