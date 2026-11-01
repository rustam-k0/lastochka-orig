<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Создать pivot-таблицу (если ещё не создана — идемпотентность при повторном запуске)
        if (! Schema::hasTable('category_store')) {
            Schema::create('category_store', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['category_id', 'store_id']);
                $table->index(['store_id', 'is_active', 'position']);
            });
        }

        // 2. Перенести данные: для каждой категории создать pivot-запись
        //    Пропускаем если данные уже перенесены (идемпотентность)
        if (Schema::hasColumn('categories', 'store_id') && DB::table('category_store')->count() === 0) {
            $now = now()->toDateTimeString();
            DB::table('categories')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->each(function ($category) use ($now) {
                    DB::table('category_store')->insert([
                        'category_id' => $category->id,
                        'store_id' => $category->store_id,
                        'position' => $category->position ?? 0,
                        'is_active' => $category->is_active ?? true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }

        // 3. Мержим дубликаты и убираем store_id (только если store_id существует)
        if (Schema::hasColumn('categories', 'store_id')) {
            $this->mergeDuplicates();

            // 4. Убрать store_id из categories
            //    Каждый drop в отдельном try-catch — идемпотентность при частичном выполнении
            $drops = [
                fn (Blueprint $table) => $table->dropForeign(['store_id']),
                fn (Blueprint $table) => $table->dropIndex('categories_store_id_parent_id_index'),
                fn (Blueprint $table) => $table->dropIndex('categories_store_id_is_active_position_index'),
                fn (Blueprint $table) => $table->dropIndex('categories_store_id_source_index'),
            ];

            foreach ($drops as $drop) {
                try {
                    Schema::table('categories', function (Blueprint $table) use ($drop) {
                        $drop($table);
                    });
                } catch (\Illuminate\Database\QueryException $e) {
                    // Уже удалено в предыдущем частичном запуске — пропускаем
                }
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('store_id');
            });
        }

        // 5. Новые индексы на categories (без store_id)
        if (! Schema::hasColumn('categories', 'store_id')) {
            try {
                Schema::table('categories', function (Blueprint $table) {
                    $table->index('parent_id');
                    $table->index('source');
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Индексы уже существуют — пропускаем
            }
        }
    }

    public function down(): void
    {
        // Вернуть store_id в categories
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['source']);
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Восстановить store_id из pivot
        DB::statement('
            UPDATE categories c
            JOIN category_store cs ON cs.category_id = c.id
            SET c.store_id = cs.store_id
        ');

        // Вернуть индексы
        Schema::table('categories', function (Blueprint $table) {
            $table->index(['store_id', 'parent_id']);
            $table->index(['store_id', 'is_active', 'position']);
            $table->index(['store_id', 'source']);
        });

        Schema::dropIfExists('category_store');
    }

    /**
     * Мержим дубликаты: категории с одинаковым name на одном уровне иерархии.
     * Каноническая — с минимальным id. Остальные — переносим связи и soft-delete.
     */
    private function mergeDuplicates(): void
    {
        // Находим дубликаты корневых категорий
        $duplicateGroups = DB::select('
            SELECT name, MIN(id) as canonical_id, GROUP_CONCAT(id) as all_ids, COUNT(*) as cnt
            FROM categories
            WHERE parent_id IS NULL AND deleted_at IS NULL
            GROUP BY name
            HAVING cnt > 1
        ');

        foreach ($duplicateGroups as $group) {
            $canonicalId = $group->canonical_id;
            $allIds = array_map('intval', explode(',', $group->all_ids));
            $duplicateIds = array_filter($allIds, fn ($id) => $id !== $canonicalId);

            if (empty($duplicateIds)) {
                continue;
            }

            $placeholders = implode(',', $duplicateIds);

            // Перенести pivot-записи дубликатов на каноническую (игнорировать уже существующие)
            DB::statement("
                INSERT IGNORE INTO category_store (category_id, store_id, position, is_active, created_at, updated_at)
                SELECT {$canonicalId}, store_id, position, is_active, created_at, updated_at
                FROM category_store
                WHERE category_id IN ({$placeholders})
            ");

            // Удалить pivot-записи дубликатов
            DB::table('category_store')->whereIn('category_id', $duplicateIds)->delete();

            // Мержить дочерние категории дубликатов
            $this->mergeChildren($canonicalId, $duplicateIds);

            // Перенести товары
            DB::statement("
                UPDATE products SET category_id = {$canonicalId}
                WHERE category_id IN ({$placeholders})
            ");

            // Перенести баннеры
            DB::statement("
                UPDATE banners SET link_category_id = {$canonicalId}
                WHERE link_category_id IN ({$placeholders})
            ");

            // Обновить event_sync_metadata
            DB::statement("
                UPDATE event_sync_metadata SET entity_id = {$canonicalId}
                WHERE entity_type = 'categories' AND entity_id IN ({$placeholders})
            ");

            // Soft-delete дубликаты
            DB::table('categories')
                ->whereIn('id', $duplicateIds)
                ->update(['deleted_at' => now()]);
        }
    }

    /**
     * Рекурсивный мерж дочерних категорий.
     */
    private function mergeChildren(int $canonicalParentId, array $duplicateParentIds): void
    {
        // Найти дочерние дубликатов
        $childrenOfDuplicates = DB::table('categories')
            ->whereIn('parent_id', $duplicateParentIds)
            ->whereNull('deleted_at')
            ->get();

        foreach ($childrenOfDuplicates as $child) {
            // Есть ли уже ребенок с таким же именем у канонического родителя?
            $existingChild = DB::table('categories')
                ->where('parent_id', $canonicalParentId)
                ->where('name', $child->name)
                ->whereNull('deleted_at')
                ->first();

            if ($existingChild) {
                // Мержим: переносим pivot, товары, баннеры, metadata
                $canonicalChildId = $existingChild->id;
                $duplicateChildId = $child->id;

                DB::statement("
                    INSERT IGNORE INTO category_store (category_id, store_id, position, is_active, created_at, updated_at)
                    SELECT {$canonicalChildId}, store_id, position, is_active, created_at, updated_at
                    FROM category_store
                    WHERE category_id = {$duplicateChildId}
                ");

                DB::table('category_store')->where('category_id', $duplicateChildId)->delete();

                DB::table('products')
                    ->where('category_id', $duplicateChildId)
                    ->update(['category_id' => $canonicalChildId]);

                DB::table('banners')
                    ->where('link_category_id', $duplicateChildId)
                    ->update(['link_category_id' => $canonicalChildId]);

                DB::table('event_sync_metadata')
                    ->where('entity_type', 'categories')
                    ->where('entity_id', $duplicateChildId)
                    ->update(['entity_id' => $canonicalChildId]);

                // Рекурсивно мержить внуков
                $this->mergeChildren($canonicalChildId, [$duplicateChildId]);

                // Soft-delete дочерний дубликат
                DB::table('categories')
                    ->where('id', $duplicateChildId)
                    ->update(['deleted_at' => now()]);
            } else {
                // Нет дубликата — просто перекинуть parent_id на канонического
                DB::table('categories')
                    ->where('id', $child->id)
                    ->update(['parent_id' => $canonicalParentId]);
            }
        }
    }
};
