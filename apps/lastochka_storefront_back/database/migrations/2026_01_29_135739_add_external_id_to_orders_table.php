<?php

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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('id');
            $table->string('external_source')->nullable()->after('external_id');

            $table->index(['external_id', 'external_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['external_id', 'external_source']);
            $table->dropColumn(['external_id', 'external_source']);
        });
    }
};
