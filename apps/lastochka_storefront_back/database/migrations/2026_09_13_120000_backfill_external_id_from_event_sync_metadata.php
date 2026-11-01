<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Трансформеры акций, товарных групп, промокодов, баннеров, историй и брендов не писали
 * external_id в модель, поэтому у всех существующих записей он NULL. По нему пакет ищет
 * сущность в findExistingEntity(), когда метаданных нет или entity_id в них потерян:
 * удаление в таком случае подтверждалось, а сущность оставалась на витрине.
 *
 * Восстанавливаем external_id из event_sync_metadata: самая свежая запись того же
 * entity_type и source. Уже заполненные значения не трогаем.
 */
return new class extends Migration
{
    /** entity_type в event_sync_metadata => таблица */
    private const TABLES = [
        'merchant_sales' => 'promotions',
        'merchant_product_groups' => 'product_groups',
        'merchant_promocodes' => 'promocodes',
        'merchant_banners' => 'banners',
        'stories' => 'stories',
        'brands' => 'brands',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('event_sync_metadata')) {
            return;
        }

        foreach (self::TABLES as $entityType => $table) {
            if (! Schema::hasColumn($table, 'external_id') || ! Schema::hasColumn($table, 'source')) {
                continue;
            }

            // Без алиаса таблицы: UPDATE с алиасом разный в MySQL и SQLite
            DB::update(
                "UPDATE {$table} SET external_id = (
                    SELECT m.external_id FROM event_sync_metadata m
                    WHERE m.entity_type = ? AND m.entity_id = {$table}.id AND m.source = {$table}.source
                    ORDER BY m.id DESC LIMIT 1
                )
                WHERE external_id IS NULL AND EXISTS (
                    SELECT 1 FROM event_sync_metadata m
                    WHERE m.entity_type = ? AND m.entity_id = {$table}.id AND m.source = {$table}.source
                )",
                [$entityType, $entityType]
            );
        }
    }

    public function down(): void
    {
        // Восстановленные external_id корректны, откат их не очищает
    }
};
