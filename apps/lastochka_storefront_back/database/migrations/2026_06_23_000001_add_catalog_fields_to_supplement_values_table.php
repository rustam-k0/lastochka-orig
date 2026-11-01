<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplement_values', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('supplement_id')
                ->constrained('products')->nullOnDelete();
            $table->unsignedInteger('max_quantity')->nullable()->after('product_id');
            $table->boolean('track_stock')->nullable()->after('max_quantity');
            $table->integer('position')->default(0)->after('track_stock');
            $table->index('product_id');
        });

        // price становится nullable (override-цена; null = цена товара-добавки)
        Schema::table('supplement_values', function (Blueprint $table) {
            $table->integer('price')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplement_values', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['max_quantity', 'track_stock', 'position']);
        });
    }
};
