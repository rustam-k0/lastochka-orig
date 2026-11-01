<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Объединение стоп-листов с полями карточки товара (Platrum 12188 / MID-155).
 *
 * Стоп-листы становятся представлением полей карточки: «не отображать» =
 * status_id != 1 (активный), «не доставлять» = pickup_only. Отдельные флаги
 * stop_list_* удаляются; даты попадания в стоп-лист переезжают в hidden_at /
 * pickup_only_at (ведутся мутаторами модели при любой смене статуса/самовывоза).
 *
 * Данные мержатся ДО удаления флагов: скрытые стоп-листом деактивируются
 * (status_id=3), недоставляемые получают pickup_only=1. Только DB::table —
 * Eloquent в миграциях валит CI на правах логов.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Переименование колонок дат (native RENAME COLUMN, MySQL 8).
        if (Schema::hasColumn('products', 'stop_list_hidden_at') && !Schema::hasColumn('products', 'hidden_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN stop_list_hidden_at TO hidden_at');
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only_at') && !Schema::hasColumn('products', 'pickup_only_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN stop_list_pickup_only_at TO pickup_only_at');
        }

        // Guard для форков без фичи стоп-листов: создать колонки с нуля.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'hidden_at')) {
                $table->timestamp('hidden_at')->nullable()->after('pickup_only')
                    ->comment('Дата скрытия товара (переход в статус «не активный»)');
            }
            if (!Schema::hasColumn('products', 'pickup_only_at')) {
                $table->timestamp('pickup_only_at')->nullable()->after('hidden_at')
                    ->comment('Дата включения «только самовывоз»');
            }
        });

        // 2) Merge данных стоп-листов в поля карточки.
        if (Schema::hasColumn('products', 'stop_list_hidden')) {
            // 3 = Status::CODE_NOT_PUBLISHED (константу не тянем в миграцию).
            DB::table('products')->where('stop_list_hidden', true)->update([
                'status_id' => 3,
                'hidden_at' => DB::raw('COALESCE(hidden_at, NOW())'),
            ]);
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only')) {
            DB::table('products')->where('stop_list_pickup_only', true)->update([
                'pickup_only' => 1,
                'pickup_only_at' => DB::raw('COALESCE(pickup_only_at, NOW())'),
            ]);
        }

        // 3) Удаление флагов.
        Schema::table('products', function (Blueprint $table) {
            foreach (['stop_list_hidden', 'stop_list_pickup_only'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Обратный merge невозможен (флаги слиты с полями карточки) —
        // восстанавливаем только структуру с default false.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden');
            }
        });

        if (Schema::hasColumn('products', 'hidden_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN hidden_at TO stop_list_hidden_at');
        }
        if (Schema::hasColumn('products', 'pickup_only_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN pickup_only_at TO stop_list_pickup_only_at');
        }
    }
};
