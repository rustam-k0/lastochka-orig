<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Добавляем shop_id после integration_type (если ещё нет)
        if (!Schema::hasColumn('integration_configs', 'shop_id')) {
            Schema::table('integration_configs', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->unsignedBigInteger('shop_id')->nullable()->after('integration_type');
                $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            });
        }

        // 2. Добавляем generated column shop_id_or_zero (если ещё нет)
        // VIRTUAL — не STORED, иначе MySQL 8 не даёт FK с ON DELETE CASCADE на base column
        if (!Schema::hasColumn('integration_configs', 'shop_id_or_zero')) {
            DB::statement('ALTER TABLE integration_configs ADD COLUMN shop_id_or_zero INT GENERATED ALWAYS AS (IFNULL(shop_id, 0)) VIRTUAL');
        }

        // 3. Удаляем старый уникальный индекс на integration_type (если есть)
        $indexes = collect(DB::select("SHOW INDEX FROM integration_configs WHERE Key_name = 'integration_configs_integration_type_unique'"));
        if ($indexes->isNotEmpty()) {
            Schema::table('integration_configs', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->dropUnique(['integration_type']);
            });
        }

        // 4. Создаём составной уникальный индекс (если ещё нет)
        $newIndex = collect(DB::select("SHOW INDEX FROM integration_configs WHERE Key_name = 'integration_configs_type_shop_unique'"));
        if ($newIndex->isEmpty()) {
            DB::statement('ALTER TABLE integration_configs ADD UNIQUE KEY integration_configs_type_shop_unique (integration_type, shop_id_or_zero)');
        }

        // 5. Backfill: устанавливаем shop_id для существующих записей type=1c
        $firstShopId = DB::table('shops')->orderBy('id')->value('id');
        if ($firstShopId !== null) {
            DB::table('integration_configs')
                ->where('integration_type', '1c')
                ->update(['shop_id' => $firstShopId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Убираем backfill
        DB::table('integration_configs')
            ->where('integration_type', '1c')
            ->update(['shop_id' => null]);

        // Удаляем составной уникальный индекс
        DB::statement('ALTER TABLE integration_configs DROP INDEX integration_configs_type_shop_unique');

        // Восстанавливаем уникальный индекс на integration_type
        Schema::table('integration_configs', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unique('integration_type');
        });

        // Удаляем generated column
        DB::statement('ALTER TABLE integration_configs DROP COLUMN shop_id_or_zero');

        // Удаляем shop_id (FK удалится автоматически)
        Schema::table('integration_configs', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropColumn('shop_id');
        });
    }
};
