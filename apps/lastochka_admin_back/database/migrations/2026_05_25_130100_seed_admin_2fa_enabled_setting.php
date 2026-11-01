<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const KEY = 'admin_2fa_enabled';

    public function up(): void
    {
        if (DB::table('admin_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('admin_settings')->insert([
            'key'        => self::KEY,
            'value'      => '0',
            'type'       => 'boolean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_settings')->where('key', self::KEY)->delete();
    }
};
