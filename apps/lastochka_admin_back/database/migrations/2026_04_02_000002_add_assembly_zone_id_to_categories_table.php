<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('assembly_zone_id')
                ->nullable()
                ->constrained('assembly_zones')
                ->nullOnDelete()
                ->after('without_blocking_price');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['assembly_zone_id']);
            $table->dropColumn('assembly_zone_id');
        });
    }
};
