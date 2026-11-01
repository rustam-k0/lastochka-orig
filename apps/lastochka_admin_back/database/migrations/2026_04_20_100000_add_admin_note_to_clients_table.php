<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('clients') && !Schema::hasColumn('clients', 'admin_note')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->text('admin_note')->nullable()->after('birthday');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'admin_note')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('admin_note');
            });
        }
    }
};
