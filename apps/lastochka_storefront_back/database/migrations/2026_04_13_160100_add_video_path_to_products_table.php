<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'video_path')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('video_path', 500)->nullable()->after('brand_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'video_path')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('video_path');
            });
        }
    }
};
