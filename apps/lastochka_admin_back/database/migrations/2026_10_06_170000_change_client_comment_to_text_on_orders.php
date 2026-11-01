<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders.client_comment: VARCHAR(255) → TEXT.
 *
 * Витрина принимает комментарий к заказу до 1000 символов (CreateOrderRequest),
 * а курьерка хранила только 255. Заказ с длинным комментарием падал в консьюмере
 * на insert (SQLSTATE 22001 Data too long) и не появлялся в админке вовсе,
 * хотя у клиента был оформлен и оплачен (холд).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'client_comment')) {
            return;
        }

        DB::statement('ALTER TABLE orders MODIFY COLUMN client_comment TEXT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'client_comment')) {
            return;
        }

        // Откат обрезал бы длинные комментарии — возвращаем VARCHAR только если их нет.
        $hasLong = DB::table('orders')->whereRaw('CHAR_LENGTH(client_comment) > 255')->exists();
        if (!$hasLong) {
            DB::statement('ALTER TABLE orders MODIFY COLUMN client_comment VARCHAR(255) NULL');
        }
    }
};
