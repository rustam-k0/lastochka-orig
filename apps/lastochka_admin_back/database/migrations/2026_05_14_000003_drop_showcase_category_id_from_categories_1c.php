<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('categories_1c', 'showcase_category_id')) {
            return;
        }

        Schema::table('categories_1c', function (Blueprint $table) {
            // Drop named composite index that includes showcase_category_id before dropping the column.
            // MySQL does not allow dropping a column that participates in an index.
            $table->dropIndex('idx_shop_showcase');
            $table->dropConstrainedForeignId('showcase_category_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories_1c', 'showcase_category_id')) {
            return;
        }

        Schema::table('categories_1c', function (Blueprint $table) {
            $table->foreignId('showcase_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->index(['shop_id', 'showcase_category_id'], 'idx_shop_showcase');
        });
    }
};
