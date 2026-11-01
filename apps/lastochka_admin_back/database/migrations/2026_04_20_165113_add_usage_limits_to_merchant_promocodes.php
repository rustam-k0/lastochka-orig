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
        Schema::table('merchant_promocodes', function (Blueprint $table) {
            $table->unsignedInteger('max_uses')->nullable()->after('is_active');
            $table->unsignedInteger('max_uses_per_client')->nullable()->after('max_uses');
            $table->unsignedInteger('used_count')->default(0)->after('max_uses_per_client');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_promocodes', function (Blueprint $table) {
            $table->dropColumn(['max_uses', 'max_uses_per_client', 'used_count']);
        });
    }
};
