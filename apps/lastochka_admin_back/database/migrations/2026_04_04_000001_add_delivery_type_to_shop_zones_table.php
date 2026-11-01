<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            $table->string('delivery_type', 10)->default('own')->after('free_delivery_from');
        });
    }

    public function down(): void
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            $table->dropColumn('delivery_type');
        });
    }
};
