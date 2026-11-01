<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исходные URL скачанных медиа (LASTOCHKI: утечка файлов 2026-07-07).
 *
 * Каждое product.updated скачивало картинки/видео/документы заново в файл со
 * случайным именем, не удаляя старые с диска. source_url позволяет пропускать
 * скачивание при неизменном URL и чистить файлы заменённых медиа.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_images', 'source_url')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->string('source_url', 2048)->nullable()->after('path');
            });
        }

        if (! Schema::hasColumn('product_documents', 'source_url')) {
            Schema::table('product_documents', function (Blueprint $table) {
                $table->string('source_url', 2048)->nullable()->after('file_path');
            });
        }

        if (! Schema::hasColumn('products', 'video_source_url')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('video_source_url', 2048)->nullable()->after('video_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_images', 'source_url')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn('source_url');
            });
        }

        if (Schema::hasColumn('product_documents', 'source_url')) {
            Schema::table('product_documents', function (Blueprint $table) {
                $table->dropColumn('source_url');
            });
        }

        if (Schema::hasColumn('products', 'video_source_url')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('video_source_url');
            });
        }
    }
};
