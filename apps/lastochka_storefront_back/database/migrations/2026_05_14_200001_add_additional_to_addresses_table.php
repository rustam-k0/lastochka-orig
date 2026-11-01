<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $t) {
            if (!Schema::hasColumn('addresses', 'additional')) {
                $t->json('additional')->nullable()->after('full_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $t) {
            if (Schema::hasColumn('addresses', 'additional')) {
                $t->dropColumn('additional');
            }
        });
    }
};
