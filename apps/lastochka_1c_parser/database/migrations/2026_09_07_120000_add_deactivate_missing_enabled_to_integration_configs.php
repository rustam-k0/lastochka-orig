<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop флаг «Удалять товары, не попавшие в полную выгрузку 1С».
 *
 * Значение приходит из admin_back событием integration_config.* (чекбокс в
 * форме 1С-интеграции). По умолчанию выключен: команда deactivate от 1С
 * игнорируется, как и раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('integration_configs', 'deactivate_missing_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->boolean('deactivate_missing_enabled')->default(false)->after('sync_images_enabled');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('integration_configs', 'deactivate_missing_enabled')) {
            return;
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->dropColumn('deactivate_missing_enabled');
        });
    }
};
