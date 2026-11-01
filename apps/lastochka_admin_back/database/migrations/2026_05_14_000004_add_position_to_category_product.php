<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('category_product', 'position')) {
            return;
        }

        Schema::table('category_product', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('category_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('category_product', 'position')) {
            return;
        }

        Schema::table('category_product', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
