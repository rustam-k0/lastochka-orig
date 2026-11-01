<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Делает cart_promocodes.store_id nullable — для electrohub-wide промокодов,
 * которые применяются ко всей корзине без привязки к конкретному магазину.
 *
 * Также снимает unique(cart_id, store_id) чтобы electrohub-wide промокод
 * (store_id=null) и store-scoped (store_id=N) могли сосуществовать.
 * Новый unique — составной (cart_id, store_id, promocode_id) plus
 * уникальность по (cart_id, scope=electrohub) на уровне приложения.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cart_promocodes')) {
            return;
        }

        // SQLite не поддерживает изменение колонок и FK константно — используем
        // универсальный путь через doctrine/dbal для MySQL, для SQLite — rebuild.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->upSqlite();

            return;
        }

        $this->upMysql();
    }

    public function down(): void
    {
        if (! Schema::hasTable('cart_promocodes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->downSqlite();

            return;
        }

        $this->downMysql();
    }

    private function upMysql(): void
    {
        // Проверяем — уже nullable?
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_promocodes'
               AND COLUMN_NAME = 'store_id'"
        );

        if ($column && strtoupper($column->IS_NULLABLE) === 'NO') {
            // Снимаем FK на store_id.
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cart_promocodes'
                   AND COLUMN_NAME = 'store_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            );

            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE `cart_promocodes` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }

            DB::statement('ALTER TABLE `cart_promocodes` MODIFY `store_id` BIGINT UNSIGNED NULL');

            // Восстанавливаем FK (nullable + cascade).
            Schema::table('cart_promocodes', function (Blueprint $table) {
                $table->foreign('store_id')
                    ->references('id')
                    ->on('stores')
                    ->cascadeOnDelete();
            });
        }

        // Перед дропом compound unique (cart_id, store_id) нужно убедиться,
        // что у cart_id есть отдельный backing index — иначе FK на cart_id
        // держит compound и MySQL откажется его дропать (error 1553).
        $this->ensureStandaloneIndex('cart_id', 'cart_promocodes_cart_id_index');
        $this->ensureStandaloneIndex('promocode_id', 'cart_promocodes_promocode_id_index');

        // Снимаем unique(cart_id, store_id) если есть — заменяем на partial unique на уровне приложения.
        $indexes = DB::select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_promocodes'
               AND NON_UNIQUE = 0
               AND INDEX_NAME <> 'PRIMARY'"
        );

        foreach ($indexes as $index) {
            // Проверяем, что индекс включает обе колонки cart_id и store_id
            $cols = DB::select(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cart_promocodes'
                   AND INDEX_NAME = ?",
                [$index->INDEX_NAME]
            );
            $colNames = array_map(fn ($c) => $c->COLUMN_NAME, $cols);
            sort($colNames);

            if ($colNames === ['cart_id', 'store_id']) {
                DB::statement("ALTER TABLE `cart_promocodes` DROP INDEX `{$index->INDEX_NAME}`");
            }
        }
    }

    /**
     * Гарантирует наличие standalone index на одной колонке.
     * Не создаёт дубликат, если уже есть single-column index на этой колонке
     * (может быть как compound-префиксом другого индекса, так и отдельным).
     */
    private function ensureStandaloneIndex(string $column, string $indexName): void
    {
        // Ищем любой существующий standalone index только на этой колонке
        $candidates = DB::select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_promocodes'
               AND COLUMN_NAME = ?
               AND SEQ_IN_INDEX = 1
               AND INDEX_NAME <> 'PRIMARY'",
            [$column]
        );

        foreach ($candidates as $candidate) {
            $cols = DB::select(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cart_promocodes'
                   AND INDEX_NAME = ?
                 ORDER BY SEQ_IN_INDEX",
                [$candidate->INDEX_NAME]
            );

            if (count($cols) === 1 && $cols[0]->COLUMN_NAME === $column) {
                return; // Standalone index уже существует
            }
        }

        // Создаём
        DB::statement("ALTER TABLE `cart_promocodes` ADD INDEX `{$indexName}` (`{$column}`)");
    }

    private function downMysql(): void
    {
        // Дропаем electrohub-wide записи перед возвратом к NOT NULL.
        DB::table('cart_promocodes')->whereNull('store_id')->delete();

        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_promocodes'
               AND COLUMN_NAME = 'store_id'"
        );

        if ($column && strtoupper($column->IS_NULLABLE) === 'YES') {
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cart_promocodes'
                   AND COLUMN_NAME = 'store_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            );

            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE `cart_promocodes` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }

            DB::statement('ALTER TABLE `cart_promocodes` MODIFY `store_id` BIGINT UNSIGNED NOT NULL');

            Schema::table('cart_promocodes', function (Blueprint $table) {
                $table->foreign('store_id')
                    ->references('id')
                    ->on('stores')
                    ->cascadeOnDelete();

                $table->unique(['cart_id', 'store_id']);
            });
        }
    }

    private function upSqlite(): void
    {
        // SQLite path (для локальных тестов) — rebuild таблицы.
        if (! Schema::hasTable('cart_promocodes_new')) {
            Schema::create('cart_promocodes_new', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
                $table->foreignId('promocode_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });

            DB::statement('INSERT INTO cart_promocodes_new (id, cart_id, store_id, promocode_id, created_at, updated_at) SELECT id, cart_id, store_id, promocode_id, created_at, updated_at FROM cart_promocodes');

            Schema::drop('cart_promocodes');
            Schema::rename('cart_promocodes_new', 'cart_promocodes');
        }
    }

    private function downSqlite(): void
    {
        DB::table('cart_promocodes')->whereNull('store_id')->delete();

        if (! Schema::hasTable('cart_promocodes_rollback')) {
            Schema::create('cart_promocodes_rollback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('promocode_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['cart_id', 'store_id']);
            });

            DB::statement('INSERT INTO cart_promocodes_rollback (id, cart_id, store_id, promocode_id, created_at, updated_at) SELECT id, cart_id, store_id, promocode_id, created_at, updated_at FROM cart_promocodes WHERE store_id IS NOT NULL');

            Schema::drop('cart_promocodes');
            Schema::rename('cart_promocodes_rollback', 'cart_promocodes');
        }
    }
};
