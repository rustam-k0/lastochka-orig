<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_groups', 'description')) {
            return;
        }

        Schema::table('product_groups', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
