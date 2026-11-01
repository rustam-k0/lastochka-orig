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
        Schema::table('payment_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_receipts', 'provider')) {
                $table->string('provider')->default('yookassa')->after('payment_transaction_id');
            }
            if (! Schema::hasColumn('payment_receipts', 'external_id')) {
                $table->string('external_id')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('payment_receipts', 'receipt_url')) {
                $table->string('receipt_url')->nullable()->after('fiscal_attribute');
            }
        });

        // Back-fill: старые строки = yookassa, external_id = yookassa_receipt_id
        DB::table('payment_receipts')
            ->whereNull('external_id')
            ->update(['external_id' => DB::raw('yookassa_receipt_id'), 'provider' => 'yookassa']);

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('yookassa_receipt_id')->nullable()->change();
        });

        // Заменить unique(yookassa_receipt_id) на unique(provider, external_id).
        // Имя индекса по умолчанию: payment_receipts_yookassa_receipt_id_unique
        try {
            Schema::table('payment_receipts', function (Blueprint $table) {
                $table->dropUnique('payment_receipts_yookassa_receipt_id_unique');
            });
        } catch (\Throwable $e) {
            // индекс мог не существовать в тестовой БД — игнорируем
        }
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn(['provider', 'external_id', 'receipt_url']);
        });
    }
};
