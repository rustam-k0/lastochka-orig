<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'is_object')) {
                $table->boolean('is_object')->default(false)->after('full_address');
                $table->index('is_object', 'addresses_is_object_index');
            }
            if (! Schema::hasColumn('addresses', 'object_data')) {
                $table->json('object_data')->nullable()->after('is_object');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'object_data')) {
                $table->dropColumn('object_data');
            }
            if (Schema::hasColumn('addresses', 'is_object')) {
                $table->dropIndex('addresses_is_object_index');
                $table->dropColumn('is_object');
            }
        });
    }
};
