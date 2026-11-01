<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_images', 'type')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->string('type', 20)->default('image')->after('path');
                $table->string('mime_type', 100)->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['type', 'mime_type']);
        });
    }
};
