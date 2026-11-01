<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'category_1c_id')) {
                $table->unsignedBigInteger('category_1c_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('products', 'showcase_category_id')) {
                $table->unsignedBigInteger('showcase_category_id')->nullable()->after('category_1c_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_1c_id')
                ->references('id')->on('categories_1c')
                ->onDelete('set null');

            $table->foreign('showcase_category_id')
                ->references('id')->on('categories')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_1c_id']);
            $table->dropForeign(['showcase_category_id']);

            $table->dropColumn(['category_1c_id', 'showcase_category_id']);
        });
    }
};
