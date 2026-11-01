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
        Schema::table('products', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('store_id');
            $table->json('barcodes')->nullable()->after('sku');
            $table->integer('vat_rate')->nullable()->after('weight');
            $table->boolean('is_culinary')->default(false)->after('is_active');
            $table->string('meta_title', 300)->nullable()->after('description');
            $table->text('meta_description')->nullable()->after('meta_title');
            $table->text('meta_keywords')->nullable()->after('meta_description');

            $table->index(['store_id', 'source']);
            $table->index(['sku', 'store_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'source']);
            $table->dropIndex(['sku', 'store_id']);
            $table->dropColumn([
                'source',
                'barcodes',
                'vat_rate',
                'is_culinary',
                'meta_title',
                'meta_description',
                'meta_keywords',
            ]);
        });
    }
};
