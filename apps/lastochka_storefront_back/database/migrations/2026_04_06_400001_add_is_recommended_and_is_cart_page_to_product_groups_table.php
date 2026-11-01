<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('product_groups', 'is_recommended')) {
                $table->boolean('is_recommended')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('product_groups', 'is_cart_page')) {
                $table->boolean('is_cart_page')->default(false)->after('is_recommended');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn(['is_recommended', 'is_cart_page']);
        });
    }
};
