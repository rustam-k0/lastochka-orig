<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Добавляет shop_code в entity_hashes для корректной работы
     * с мультишопными сущностями (одна Bagisto-категория → несколько RetailCRM сайтов).
     * Без этого возникает hash collision и бесконечный цикл событий.
     */
    public function up(): void
    {
        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->string('shop_code')->nullable()->default('default')->after('entity_id');
        });

        // Удаляем старый UNIQUE индекс и обычный индекс
        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->dropUnique(['entity_type', 'entity_id']);
            $table->dropIndex(['entity_type', 'entity_id']);
        });

        // Создаём новый UNIQUE индекс с shop_code
        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->unique(['entity_type', 'entity_id', 'shop_code']);
            $table->index(['entity_type', 'entity_id', 'shop_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->dropUnique(['entity_type', 'entity_id', 'shop_code']);
            $table->dropIndex(['entity_type', 'entity_id', 'shop_code']);
        });

        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->unique(['entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::table('entity_hashes', function (Blueprint $table) {
            $table->dropColumn('shop_code');
        });
    }
};
