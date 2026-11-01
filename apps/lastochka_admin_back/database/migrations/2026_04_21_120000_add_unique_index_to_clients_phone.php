<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'clients_phone_active_unique';

    /**
     * Добавляем UNIQUE INDEX на phone только для активных (не soft-deleted) клиентов.
     * Это защитит от race condition в RabbitMQ-консюмере, который исторически
     * порождал дубли клиентов по одному номеру (см. ClientEventTransformer).
     *
     * Миграция фейлится, если в таблице остались дубли — их сначала надо слить
     * командой `php artisan clients:dedupe --apply`.
     */
    public function up(): void
    {
        $this->assertNoDuplicates();

        if ($this->indexExists()) {
            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON clients ((CASE WHEN deleted_at IS NULL THEN phone ELSE NULL END))',
            self::INDEX_NAME
        ));
    }

    public function down(): void
    {
        if (!$this->indexExists()) {
            return;
        }

        DB::statement(sprintf('DROP INDEX %s ON clients', self::INDEX_NAME));
    }

    private function assertNoDuplicates(): void
    {
        $duplicates = DB::table('clients')
            ->whereNull('deleted_at')
            ->whereNotNull('phone')
            ->selectRaw('phone, COUNT(*) as cnt')
            ->groupBy('phone')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $phones = $duplicates->pluck('phone')->take(5)->implode(', ');
        $more = $duplicates->count() > 5 ? ' и ещё ' . ($duplicates->count() - 5) : '';

        throw new \RuntimeException(
            "Невозможно добавить UNIQUE INDEX на clients.phone — найдено {$duplicates->count()} групп-дублей: "
            . "{$phones}{$more}. "
            . 'Сначала слить их командой: php artisan clients:dedupe --apply'
        );
    }

    private function indexExists(): bool
    {
        if (!Schema::hasTable('clients')) {
            return false;
        }

        $exists = DB::selectOne(
            "SELECT 1 AS e FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND INDEX_NAME = ?",
            [self::INDEX_NAME]
        );

        return (bool) $exists;
    }
};
