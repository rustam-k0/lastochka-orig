<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop чекбокс «Удалять товары, не попавшие в полную выгрузку 1С» в форме
 * 1С-интеграции. Значение уезжает в 1c_parser событием integration_config.*;
 * по умолчанию выключен, команда deactivate от 1С игнорируется, как раньше.
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
