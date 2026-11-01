<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'category_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // Дропаем составной индекс до удаления колонки (SQLite не поддерживает
            // drop column если колонка входит в индекс).
            $table->dropIndex('products_store_id_category_id_is_active_index');
            $table->dropConstrainedForeignId('category_id');
            // Восстанавливаем полезную часть индекса без category_id.
            $table->index(['store_id', 'is_active'], 'products_store_id_is_active_index_v2');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'category_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_store_id_is_active_index_v2');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['store_id', 'category_id', 'is_active'], 'products_store_id_category_id_is_active_index');
        });
    }
};
