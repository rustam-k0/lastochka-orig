<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коды маркировки (Честный знак, MID-266), отсканированные сборщиком:
 * один ряд на каждую отсканированную единицу товара.
 *
 * Общая БД курьерских модулей: пишет delivery_back, читают admin_back и
 * purchase_back (транспорт в события заказа и отображение в админке).
 * order_item_id и product_id денормализованы, чтобы читать без join'а
 * с assembly_items. Уникальности по code нет намеренно: код отменённого
 * заказа может быть законно отсканирован снова, занятость проверяет
 * приложение по активным заказам.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assembly_item_marking_codes')) {
            return;
        }

        Schema::create('assembly_item_marking_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assembly_item_id');
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('code', 255)->index()->comment('Код маркировки (DataMatrix/КИЗ) как отсканирован');
            $table->string('barcode', 64)->nullable()->comment('Штрихкод товара, с которым код проверялся в 1С');
            $table->dateTime('verified_at')->nullable()->comment('Момент успешной проверки в 1С (CheckCIS)');
            $table->string('verify_message', 255)->nullable()->comment('Ответ 1С на проверку');
            $table->timestamps();

            $table->foreign('assembly_item_id')
                ->references('id')
                ->on('assembly_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_item_marking_codes');
    }
};
