<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'zone_id')) {
                $table->foreignId('zone_id')->nullable()->after('assembly_zone_id')
                    ->constrained('shop_zones')
                    ->comment('Зона доставки (point-in-polygon по координатам client_infos); NULL = не определена');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'zone_id')) {
                $table->dropConstrainedForeignId('zone_id');
            }
        });
    }
};
