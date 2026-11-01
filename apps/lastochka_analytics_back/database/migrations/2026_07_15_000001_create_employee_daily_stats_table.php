<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Дневные агрегаты сотрудника (#11596, эпик B2E #11595).
     *
     * Одна строка = сотрудник + роль + дата. role входит в UNIQUE: сотрудник
     * может за день отработать и сборщиком, и курьером — это разные наборы
     * метрик. Заполняется командой analytics:aggregate-daily (идемпотентный
     * полный пересчёт дня, upsert).
     */
    public function up(): void
    {
        Schema::create('employee_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('role'); // picker | courier
            $table->string('store_id')->nullable(); // shop_code последнего события дня
            $table->date('stat_date');

            $table->integer('orders_count')->default(0);     // собрано / доставлено
            $table->integer('items_count')->default(0);
            $table->decimal('gmv_amount', 14, 2)->default(0); // сумма заказов, ₽
            $table->integer('avg_handle_sec')->nullable();    // ср. время сборки/доставки
            $table->integer('on_time_count')->default(0);     // в рамках SLA
            $table->integer('sla_total')->default(0);
            $table->integer('replace_count')->default(0);
            $table->integer('remove_count')->default(0);
            $table->integer('scan_qr_count')->default(0);
            $table->integer('scan_manual_count')->default(0);
            $table->integer('scan_error_count')->default(0);
            $table->decimal('rating_sum', 8, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->integer('complaint_count')->default(0);
            $table->bigInteger('distance_m')->default(0);     // для курьера
            $table->integer('shift_seconds')->default(0);     // из shift.started/ended

            $table->timestamps();

            $table->unique(['employee_id', 'role', 'stat_date']);
            $table->index(['store_id', 'stat_date']);
            $table->index('stat_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_daily_stats');
    }
};
