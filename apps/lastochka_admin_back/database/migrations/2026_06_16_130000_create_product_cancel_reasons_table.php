<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник причин отмены товара при сборке.
     * Управляется из админки (раздел «Причины отмены товара»),
     * читается сборщиком в delivery (query product_cancel_reasons).
     */
    public function up(): void
    {
        if (!Schema::hasTable('product_cancel_reasons')) {
            Schema::create('product_cancel_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->boolean('is_active')->default(true);
                $table->integer('position')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        // Дефолтные причины — только при пустой таблице (идемпотентно).
        if (DB::table('product_cancel_reasons')->count() === 0) {
            $now = now();
            $defaults = [
                'Нет в наличии',
                'Брак товара',
                'Истёк срок годности',
                'Повреждена упаковка',
                'Клиент отказался',
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

            DB::table('product_cancel_reasons')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cancel_reasons');
    }
};
