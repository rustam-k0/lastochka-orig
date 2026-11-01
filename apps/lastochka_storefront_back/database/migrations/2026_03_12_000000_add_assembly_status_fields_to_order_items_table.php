<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('image_url');
            $table->unsignedBigInteger('origin_product_id')->nullable()->after('status');
            $table->string('origin_title', 300)->nullable()->after('origin_product_id');
            $table->integer('origin_price')->nullable()->after('origin_title');
            $table->integer('origin_quantity')->nullable()->after('origin_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'origin_product_id', 'origin_title', 'origin_price', 'origin_quantity']);
        });
    }
};
