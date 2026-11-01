<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('promocodes', function (Blueprint $table) {
            $table->enum('binding_type', ['all', 'products', 'categories'])
                ->default('all')
                ->after('is_active');
        });

        if (! Schema::hasTable('promocode_products')) {
            Schema::create('promocode_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promocode_id')->constrained('promocodes')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['promocode_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('promocode_categories')) {
            Schema::create('promocode_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promocode_id')->constrained('promocodes')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['promocode_id', 'category_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_categories');
        Schema::dropIfExists('promocode_products');

        Schema::table('promocodes', function (Blueprint $table) {
            $table->dropColumn('binding_type');
        });
    }
};
