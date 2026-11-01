<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Комментарий колонки `products.vat_code` отстал от справочника: с 01.01.2026
 * базовая ставка НДС в РФ — 22% (ФЗ от 28.11.2025 №425-ФЗ), которой в кодах
 * ЮKassa соответствуют 11 (22%) и 12 (22/122). Именно их присылает
 * lastochka_1c_parser, а прежний комментарий «(1-6)» вводил в заблуждение.
 * Меняется только COMMENT — тип и nullable остаются как в исходной миграции.
 */
return new class extends Migration
{
    private const COMMENT_NEW = 'ставка НДС ЮKassa (1-6, 11 = 22%, 12 = 22/122)';

    private const COMMENT_OLD = 'ставка НДС ЮKassa (1-6)';

    public function up(): void
    {
        $this->setComment(self::COMMENT_NEW);
    }

    public function down(): void
    {
        $this->setComment(self::COMMENT_OLD);
    }

    private function setComment(string $comment): void
    {
        // Guard: на форках, где миграция vat_code ещё не накатана, колонки нет.
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'vat_code')) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `products` MODIFY `vat_code` TINYINT UNSIGNED NULL COMMENT %s',
            DB::getPdo()->quote($comment)
        ));
    }
};
