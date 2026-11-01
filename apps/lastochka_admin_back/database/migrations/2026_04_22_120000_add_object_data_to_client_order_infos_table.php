<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_order_infos', function (Blueprint $table) {
            if (! Schema::hasColumn('client_order_infos', 'object_data')) {
                $table->json('object_data')->nullable()->after('flat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_order_infos', function (Blueprint $table) {
            if (Schema::hasColumn('client_order_infos', 'object_data')) {
                $table->dropColumn('object_data');
            }
        });
    }
};
