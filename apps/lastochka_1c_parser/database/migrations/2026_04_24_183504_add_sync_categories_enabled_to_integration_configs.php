<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('integration_configs', 'sync_categories_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->boolean('sync_categories_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('integration_configs', 'sync_categories_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->dropColumn('sync_categories_enabled');
        });
    }
};
