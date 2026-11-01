<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL 8 не позволяет FK с ON DELETE CASCADE на base column STORED generated column.
     * Меняем shop_id_or_zero с STORED на VIRTUAL и добавляем FK.
     */
    public function up(): void
    {
        // 1. Проверяем, нужен ли фикс: если колонка STORED — меняем на VIRTUAL
        $column = DB::selectOne("
            SELECT EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'integration_configs'
              AND COLUMN_NAME = 'shop_id_or_zero'
        ");

        if ($column && str_contains($column->EXTRA, 'STORED')) {
            // Дропаем unique index, который зависит от generated column
            $idx = DB::select("SHOW INDEX FROM integration_configs WHERE Key_name = 'integration_configs_type_shop_unique'");
            if (!empty($idx)) {
                DB::statement('ALTER TABLE integration_configs DROP INDEX integration_configs_type_shop_unique');
            }

            // Меняем STORED → VIRTUAL
            DB::statement('ALTER TABLE integration_configs DROP COLUMN shop_id_or_zero');
            DB::statement('ALTER TABLE integration_configs ADD COLUMN shop_id_or_zero INT GENERATED ALWAYS AS (IFNULL(shop_id, 0)) VIRTUAL');

            // Возвращаем unique index
            DB::statement('ALTER TABLE integration_configs ADD UNIQUE KEY integration_configs_type_shop_unique (integration_type, shop_id_or_zero)');
        }

        // 2. Добавляем FK если нет
        $hasFk = DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'integration_configs'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'integration_configs_shop_id_foreign'
        ");

        if (empty($hasFk) && Schema::hasColumn('integration_configs', 'shop_id')) {
            DB::statement('ALTER TABLE integration_configs ADD CONSTRAINT integration_configs_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Дропаем FK
        $hasFk = DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'integration_configs'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'integration_configs_shop_id_foreign'
        ");

        if (!empty($hasFk)) {
            DB::statement('ALTER TABLE integration_configs DROP FOREIGN KEY integration_configs_shop_id_foreign');
        }
    }
};
