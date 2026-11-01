<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories_1c')) {
            return;
        }

        Schema::create('categories_1c', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('external_id', 64);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('showcase_category_id')->nullable();
            $table->timestamp('linked_at')->nullable()
                ->comment('Когда админ привязал эту 1С-категорию к витринной (showcase_category_id)');
            $table->timestamp('last_synced_at')->nullable()
                ->comment('Когда последний раз сработал SyncCategoryMappingsJob и применил привязку к товарам');
            $table->timestamp('synced_from_1c_at')->nullable()
                ->comment('Когда последний раз получили событие category_1c.* из RabbitMQ от 1c_parser');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')
                ->references('id')->on('shops')
                ->onDelete('cascade');

            $table->foreign('parent_id')
                ->references('id')->on('categories_1c')
                ->onDelete('set null');

            $table->foreign('showcase_category_id')
                ->references('id')->on('categories')
                ->onDelete('set null');

            $table->unique(['shop_id', 'external_id', 'deleted_at'], 'uniq_shop_external_deleted');
            $table->index(['shop_id', 'showcase_category_id'], 'idx_shop_showcase');
            $table->index(['shop_id', 'deleted_at', 'name'], 'idx_shop_deleted_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories_1c');
    }
};
