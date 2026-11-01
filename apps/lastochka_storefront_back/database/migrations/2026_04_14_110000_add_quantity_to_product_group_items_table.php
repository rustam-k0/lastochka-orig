<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_group_items', 'quantity')) {
            return;
        }

        Schema::table('product_group_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('product_group_items', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
