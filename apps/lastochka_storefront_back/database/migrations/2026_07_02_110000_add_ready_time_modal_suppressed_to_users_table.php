<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'ready_time_modal_suppressed')) {
                $table->boolean('ready_time_modal_suppressed')->default(false)->after('push_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'ready_time_modal_suppressed')) {
                $table->dropColumn('ready_time_modal_suppressed');
            }
        });
    }
};
