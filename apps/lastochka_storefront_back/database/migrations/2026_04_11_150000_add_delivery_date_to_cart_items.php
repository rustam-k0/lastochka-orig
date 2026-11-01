<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cart_items', 'delivery_date')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->date('delivery_date')->nullable()->after('store_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cart_items', 'delivery_date')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropColumn('delivery_date');
            });
        }
    }
};
