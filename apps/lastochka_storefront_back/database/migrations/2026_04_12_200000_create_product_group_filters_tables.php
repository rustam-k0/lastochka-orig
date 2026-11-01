<?php

declare(strict_types=1);

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

        if (!Schema::hasTable('product_group_filter_value')) {
            Schema::create('product_group_filter_value', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_group_id')
                    ->constrained('product_groups')->cascadeOnDelete();
                $table->foreignId('product_group_filter_value_id')
                    ->constrained('product_group_filter_values')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['product_group_id', 'product_group_filter_value_id'],
                    'pg_filter_value_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_filter_value');
        Schema::dropIfExists('product_group_filter_values');
        Schema::dropIfExists('product_group_filters');
    }
};
