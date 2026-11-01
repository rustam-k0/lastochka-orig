<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_group_filters')) {
            Schema::create('product_group_filters', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->integer('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_group_filter_values')) {
            Schema::create('product_group_filter_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_group_filter_id')
                    ->constrained('product_group_filters')->cascadeOnDelete();
                $table->string('value', 100);
                $table->string('image_path', 500)->nullable();
                $table->integer('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('merchant_product_group_filter_values')) {
            Schema::create('merchant_product_group_filter_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_product_group_id');
                $table->unsignedBigInteger('product_group_filter_value_id');
                $table->timestamps();

                $table->foreign('merchant_product_group_id', 'mpg_fv_group_fk')
                    ->references('id')->on('merchant_product_groups')->cascadeOnDelete();
                $table->foreign('product_group_filter_value_id', 'mpg_fv_value_fk')
                    ->references('id')->on('product_group_filter_values')->cascadeOnDelete();
                $table->unique(
                    ['merchant_product_group_id', 'product_group_filter_value_id'],
                    'mpg_filter_value_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_product_group_filter_values');
        Schema::dropIfExists('product_group_filter_values');
        Schema::dropIfExists('product_group_filters');
    }
};
