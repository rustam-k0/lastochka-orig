<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'background_image_path')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->string('background_image_path', 500)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('categories', 'background_image_path')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('background_image_path');
        });
    }
};
