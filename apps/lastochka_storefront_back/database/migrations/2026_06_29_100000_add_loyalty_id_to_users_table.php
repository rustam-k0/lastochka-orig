<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'loyalty_id')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('loyalty_id', 64)->nullable()->unique()->after('external_source');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'loyalty_id')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('loyalty_id');
        });
    }
};
