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
        Schema::table('promocodes', function (Blueprint $table) {
            $table->unsignedInteger('max_uses_per_client')->nullable()->after('max_uses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promocodes', function (Blueprint $table) {
            $table->dropColumn('max_uses_per_client');
        });
    }
};
