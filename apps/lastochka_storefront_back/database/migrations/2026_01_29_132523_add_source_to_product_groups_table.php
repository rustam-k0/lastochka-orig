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
        Schema::table('product_groups', function (Blueprint $table) {
            $table->string('source')->nullable()->after('store_id');
            $table->string('external_id')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_id']);
        });
    }
};
