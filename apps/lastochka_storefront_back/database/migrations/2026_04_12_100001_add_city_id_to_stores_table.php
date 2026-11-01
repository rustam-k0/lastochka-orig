<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stores', 'city_id')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('name')
                ->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
