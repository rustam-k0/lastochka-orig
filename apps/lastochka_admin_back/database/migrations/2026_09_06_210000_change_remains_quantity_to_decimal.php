<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Остаток весового товара из 1С — килограммы с дробной частью (0.828, 2.168).
     * `remains.quantity` была `bigint unsigned` (см. 2026_04_22): дробь усекалась,
     * остаток меньше килограмма хранился как 0, и эхо product.updated от admin_back
     * уносило этот ноль на витрину. DECIMAL(14,3) хранит точность до грамма и
     * по-прежнему вмещает тонны (1 000 000 г = 1000 кг).
     */
    public function up(): void
    {
        if (!Schema::hasTable('remains') || !Schema::hasColumn('remains', 'quantity')) {
            return;
        }

        DB::statement('ALTER TABLE remains MODIFY quantity DECIMAL(14,3) UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('remains') || !Schema::hasColumn('remains', 'quantity')) {
            return;
        }

        DB::statement('ALTER TABLE remains MODIFY quantity BIGINT UNSIGNED NOT NULL');
    }
};
