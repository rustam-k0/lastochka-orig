<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('promocodes', 'scope')) {
            Schema::table('promocodes', function (Blueprint $table) {
                // Для SQLite совместимости — string с валидацией на уровне PHP/модели.
                $table->string('scope', 32)->default('store')->after('store_id');
            });
        }

        if (! Schema::hasColumn('promocodes', 'eligible_amount_threshold')) {
            Schema::table('promocodes', function (Blueprint $table) {
                $table->decimal('eligible_amount_threshold', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('promocodes', 'scope')) {
            Schema::table('promocodes', function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }

        if (Schema::hasColumn('promocodes', 'eligible_amount_threshold')) {
            Schema::table('promocodes', function (Blueprint $table) {
                $table->dropColumn('eligible_amount_threshold');
            });
        }
    }
};
