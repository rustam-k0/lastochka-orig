<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('categories_1c', 'showcase_category_id')) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT IGNORE INTO category_1c_category (categories_1c_id, category_id, position, created_at, updated_at)
            SELECT id, showcase_category_id, 0, NOW(), NOW()
            FROM categories_1c
            WHERE showcase_category_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Безопасный откат через drop pivot из A1; backfill не реверсим, чтобы не терять данные.
    }
};
