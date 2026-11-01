<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Добавление поля `scope` к merchant_promocodes + nullable shop_id.
 *
 * Маркетплейс-режим: промокод может быть "store" (привязан к магазину,
 * как сейчас) или "electrohub" (действует на всю корзину пользователя,
 * shop_id не обязателен). Дефолт = 'store' — zero-change для стандартного
 * поведения остальных форков.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('merchant_promocodes', 'scope')) {
            Schema::table('merchant_promocodes', function (Blueprint $table) {
                $table->string('scope', 32)
                    ->default('store')
                    ->after('shop_id');
            });
        }

        // Backfill для существующих строк (дефолт 'store' уже применится,
        // но на случай если были null-значения из-за raw insert'ов).
        DB::table('merchant_promocodes')
            ->whereNull('scope')
            ->update(['scope' => 'store']);

        // Делаем shop_id nullable через raw SQL (доменный onDelete cascade
        // FK сохраняется — MySQL позволяет nullable FK). Используем guard
        // по текущей схеме: если уже nullable — пропускаем.
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'merchant_promocodes'
               AND COLUMN_NAME = 'shop_id'"
        );

        if ($column && strtoupper($column->IS_NULLABLE) === 'NO') {
            // Дропаем FK, делаем nullable, пересоздаём FK.
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'merchant_promocodes'
                   AND COLUMN_NAME = 'shop_id'
                   AND REFERENCED_TABLE_NAME = 'shops'"
            );

            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE `merchant_promocodes` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }

            DB::statement('ALTER TABLE `merchant_promocodes` MODIFY `shop_id` BIGINT UNSIGNED NULL');

            // Восстанавливаем FK (nullable + cascade).
            Schema::table('merchant_promocodes', function (Blueprint $table) {
                $table->foreign('shop_id')
                    ->references('id')
                    ->on('shops')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Откатываем scope (если колонка есть).
        if (Schema::hasColumn('merchant_promocodes', 'scope')) {
            // Для отката shop_id → NOT NULL нужно предварительно
            // либо удалить electrohub-wide записи, либо присвоить им какой-то shop_id.
            // Чтобы down был идемпотентным и не терял данные, просто удаляем записи
            // с shop_id = NULL (это electrohub-wide, которые не существовали до миграции).
            DB::table('merchant_promocodes')->whereNull('shop_id')->delete();

            Schema::table('merchant_promocodes', function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }

        // Вернуть shop_id → NOT NULL.
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'merchant_promocodes'
               AND COLUMN_NAME = 'shop_id'"
        );

        if ($column && strtoupper($column->IS_NULLABLE) === 'YES') {
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'merchant_promocodes'
                   AND COLUMN_NAME = 'shop_id'
                   AND REFERENCED_TABLE_NAME = 'shops'"
            );

            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE `merchant_promocodes` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }

            DB::statement('ALTER TABLE `merchant_promocodes` MODIFY `shop_id` BIGINT UNSIGNED NOT NULL');

            Schema::table('merchant_promocodes', function (Blueprint $table) {
                $table->foreign('shop_id')
                    ->references('id')
                    ->on('shops')
                    ->onDelete('cascade');
            });
        }
    }
};
