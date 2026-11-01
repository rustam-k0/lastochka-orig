<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        if (!Schema::hasColumn('products', 'is_default_variant')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_default_variant')->default(false)->after('is_configurable');
                $table->index(['parent_id', 'is_default_variant'], 'products_parent_default_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'is_default_variant')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_parent_default_idx');
                $table->dropColumn('is_default_variant');
            });
        }
    }
};
