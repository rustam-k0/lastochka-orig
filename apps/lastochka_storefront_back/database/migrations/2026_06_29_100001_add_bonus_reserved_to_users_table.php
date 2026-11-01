<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'bonus_reserved')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('bonus_reserved')->default(0)->after('bonus_balance');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'bonus_reserved')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bonus_reserved');
        });
    }
};
