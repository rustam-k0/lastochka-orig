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
        if (! Schema::hasColumn('banners', 'display_category_id')) {
            Schema::table('banners', function (Blueprint $table) {
                // NULL = баннер главной страницы, значение = баннер страницы категории
                $table->foreignId('display_category_id')
                    ->nullable()
                    ->after('link_category_id')
                    ->constrained('categories')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('banners', 'display_category_id')) {
            Schema::table('banners', function (Blueprint $table) {
                $table->dropConstrainedForeignId('display_category_id');
            });
        }
    }
};
