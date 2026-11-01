<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник причин отмены заказа администратором.
     * Управляется из админки (раздел «Причины отмены заказа»),
     * читается при отмене заказа в purchase_back (мутация OrderCancel).
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_cancel_reasons')) {
            Schema::create('order_cancel_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->boolean('is_active')->default(true);
                $table->integer('position')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (DB::table('order_cancel_reasons')->count() === 0) {
            $now = now();
            $defaults = [
                'Нет товара в наличии',
                'Клиент передумал / отказался',
                'Не удалось связаться с клиентом',
                'Ошибка оператора при оформлении',
                'Дубликат заказа',
                'Не подтверждён возраст/документ (ЕГАИС)',
                'Мошеннические действия',
            ];

            $rows = [];
            foreach ($defaults as $i => $title) {
                $rows[] = [
                    'title' => $title,
                    'is_active' => true,
                    'position' => $i + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('order_cancel_reasons')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancel_reasons');
    }
};
