<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Колонка `quantity` в `remains` была создана как `float(8,2) unsigned`
     * (Laravel 9 default precision/scale для `$table->float`), максимум 999999.99.
     * Бизнес хранит склад в единицах хранения товара (граммы для весовых),
     * и 1 тонна (1_000_000 г) уже не влезала — ProductUpdate падал 500'кой.
     * Трансформеры приводят значение к int, дробная часть не используется.
     */
    public function up(): void
    {
        Schema::table('remains', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->change();
        });
    }

    public function down(): void
    {
        Schema::table('remains', function (Blueprint $table) {
            $table->float('quantity', unsigned: true)->change();
        });
    }
};
