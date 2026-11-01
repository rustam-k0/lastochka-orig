<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yandex_delivery_claims', function (Blueprint $table) {
            $table->string('zone_id', 64)->nullable()->after('claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('yandex_delivery_claims', function (Blueprint $table) {
            $table->dropColumn('zone_id');
        });
    }
};
