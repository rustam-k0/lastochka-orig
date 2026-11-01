<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 36)->nullable()->comment('GUID в 1С после выгрузки');
            $table->string('number', 50)->unique()->comment('Номер заказа на сайте');
            $table->string('status', 50)->default('new')->comment('new, exported, confirmed, cancelled');
            $table->string('customer_name', 255);
            $table->string('customer_phone', 50);
            $table->string('customer_email', 255)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('comment')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamp('exported_at')->nullable()->comment('Время выгрузки в 1С');
            $table->json('one_c_response')->nullable()->comment('Ответ от 1С');
            $table->timestamps();

            $table->index('status');
            $table->index('exported_at');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
