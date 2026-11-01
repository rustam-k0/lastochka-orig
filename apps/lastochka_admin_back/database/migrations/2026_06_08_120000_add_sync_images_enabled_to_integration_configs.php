<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('integration_configs', 'sync_images_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->boolean('sync_images_enabled')->default(false)->after('sync_categories_enabled');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('integration_configs', 'sync_images_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->dropColumn('sync_images_enabled');
        });
    }
};
