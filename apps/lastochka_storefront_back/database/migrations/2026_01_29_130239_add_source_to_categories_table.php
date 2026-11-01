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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('store_id');
            $table->text('meta_keywords')->nullable()->after('meta_description');

            $table->index(['store_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'source']);
            $table->dropColumn(['source', 'meta_keywords']);
        });
    }
};
