<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shops', 'city_id')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('title')
                ->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
