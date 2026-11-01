<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Стоимость сборки в рублях, зеркало shops.assembly_price
            // (приходит по RabbitMQ из admin_back). null = не задана.
            $table->unsignedInteger('assembly_price')->nullable()->after('heavy_order_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('assembly_price');
        });
    }
};
