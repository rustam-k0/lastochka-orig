<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('is_bonus_enabled')->default(false);
            $table->unsignedTinyInteger('bonus_earn_percent')->default(0);
            $table->unsignedTinyInteger('bonus_spend_percent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['is_bonus_enabled', 'bonus_earn_percent', 'bonus_spend_percent']);
        });
    }
};
