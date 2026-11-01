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
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('store_id');

            // Делаем user_id nullable для внешних отзывов
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
