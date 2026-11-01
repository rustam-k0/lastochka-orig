<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('merchant_banners', 'display_category_id')) {
            Schema::table('merchant_banners', function (Blueprint $table) {
                // NULL = баннер главной страницы, значение = баннер страницы категории
                $table->unsignedBigInteger('display_category_id')->nullable()->after('category_id');

                $table->foreign('display_category_id')->references('id')->on('categories')->onDelete('set null');
                $table->index('display_category_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('merchant_banners', 'display_category_id')) {
            Schema::table('merchant_banners', function (Blueprint $table) {
                $table->dropForeign(['display_category_id']);
                $table->dropIndex(['display_category_id']);
                $table->dropColumn('display_category_id');
            });
        }
    }
};
