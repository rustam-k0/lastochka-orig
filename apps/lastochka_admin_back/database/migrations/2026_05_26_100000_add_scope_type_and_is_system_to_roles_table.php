<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'scope_type')) {
                $table->string('scope_type')->default('global')->after('guard_name');
            }
            if (!Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('scope_type');
            }
        });

        DB::table('roles')->where('name', 'admin')->update(['scope_type' => 'global', 'is_system' => true]);
        DB::table('roles')->where('name', 'shop')->update(['scope_type' => 'shop', 'is_system' => true]);
        DB::table('roles')->whereIn('name', ['api', 'collector', 'courier'])->update(['scope_type' => 'global', 'is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'is_system']);
        });
    }
};
