<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Состав файлов полной выгрузки каталога 1С: все Ид товаров одного файла
 * import___*.xml. Строки живут от импорта файла до ближайшего шага deactivate
 * по магазину, который их объединяет и удаляет (см. MissingProductsDeactivator).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_import_chunks')) {
            return;
        }

        Schema::create('catalog_import_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('exchange_log_id')->index();
            $table->json('external_ids');
            $table->unsignedInteger('products_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_chunks');
    }
};
