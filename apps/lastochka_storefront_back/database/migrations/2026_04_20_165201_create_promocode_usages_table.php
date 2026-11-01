<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Таблица учёта использований промокодов — для проверки per-client лимита.
     *
     * Примечание: в ТЗ указано `customer_id` → constrained('customers'),
     * но в storefront_back клиент хранится в `users` (нет отдельной customers-таблицы).
     * Поле называется `user_id` и ссылается на `users`.
     */
    public function up(): void
    {
        Schema::create('promocode_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocode_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at');
            $table->timestamps();
            $table->index(['promocode_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_usages');
    }
};
