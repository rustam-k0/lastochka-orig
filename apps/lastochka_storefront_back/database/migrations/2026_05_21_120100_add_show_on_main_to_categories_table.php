<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'show_on_main')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('show_on_main')->default(false)->after('is_perishable');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('categories', 'show_on_main')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('show_on_main');
        });
    }
};
