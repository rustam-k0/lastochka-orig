<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('parent_order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete()
                ->after('id');

            $table->unsignedTinyInteger('sub_number')
                ->nullable()
                ->after('parent_order_id');

            $table->foreignId('assembly_zone_id')
                ->nullable()
                ->constrained('assembly_zones')
                ->nullOnDelete()
                ->after('sub_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropForeign(['assembly_zone_id']);
            $table->dropColumn(['parent_order_id', 'sub_number', 'assembly_zone_id']);
        });
    }
};
