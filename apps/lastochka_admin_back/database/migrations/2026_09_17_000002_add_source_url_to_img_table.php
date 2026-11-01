<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * img.source_url — URL, с которого скачан файл (обмен 1С, MID-305).
 * По нему обмен понимает, что фото уже скачано, и не качает его повторно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('img', 'source_url')) {
            return;
        }

        Schema::table('img', function (Blueprint $table) {
            $table->string('source_url', 1000)->nullable()
                ->comment('URL, с которого скачан файл (обмен 1С)');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('img', 'source_url')) {
            return;
        }

        Schema::table('img', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
