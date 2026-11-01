<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('comment');

            // Уникальный индекс по user_id + idempotency_key для быстрой проверки
            $table->unique(['user_id', 'idempotency_key'], 'orders_user_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_user_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
