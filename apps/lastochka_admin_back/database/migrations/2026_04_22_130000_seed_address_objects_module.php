<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_settings')) {
            return;
        }

        $row = DB::table('admin_settings')->where('key', 'project_modules')->first();

        $modules = [];
        if ($row && $row->value) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $modules = $decoded;
            }
        }

        if (isset($modules['addresses.objects'])) {
            return;
        }

        $modules['addresses.objects'] = [
            'label' => 'Адрес-объект',
            'enabled' => false,
            'description' => 'Расширенный адрес доставки для коммерческих/строительных объектов: контакты на месте, лифт, пропуск, время приёма, дни работы, фото въезда',
        ];

        $encoded = json_encode($modules, JSON_UNESCAPED_UNICODE);

        if ($row) {
            DB::table('admin_settings')
                ->where('key', 'project_modules')
                ->update([
                    'value' => $encoded,
                    'type' => 'json',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('admin_settings')->insert([
                'key' => 'project_modules',
                'value' => $encoded,
                'type' => 'json',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_settings')) {
            return;
        }

        $row = DB::table('admin_settings')->where('key', 'project_modules')->first();
        if (!$row) {
            return;
        }

        $decoded = json_decode((string) $row->value, true);
        if (!is_array($decoded)) {
            return;
        }

        unset($decoded['addresses.objects']);

        DB::table('admin_settings')
            ->where('key', 'project_modules')
            ->update([
                'value' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }
};
