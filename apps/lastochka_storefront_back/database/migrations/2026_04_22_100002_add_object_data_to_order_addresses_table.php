<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('order_addresses', 'object_data')) {
                $table->json('object_data')->nullable()->after('full_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('order_addresses', 'object_data')) {
                $table->dropColumn('object_data');
            }
        });
    }
};
