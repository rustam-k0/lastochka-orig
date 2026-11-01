<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Системные роли на свежей установке.
     *
     * Раньше их создавали только сидеры (UserRolesSeeder, ApiRoleSeeder, сидеры delivery_back),
     * то есть уже после всех миграций. Миграции 2026_05_26 и позже ставят is_system/scope_type
     * и выдают права только существующим ролям, поэтому свежий форк получал роли без флагов
     * и прав. Создаём роли здесь, до этих миграций.
     *
     * Только если таблица ролей пустая: на стендах с историей роли уже есть, и набор ролей
     * у форков разный (на mendev нет shop) — там миграция ничего не меняет.
     */
    private const ROLES = [
        'admin' => 'Администратор',
        'shop' => 'Селер',
        'api' => null,
        'collector' => 'Сборщик',
        'courier' => 'Курьер',
    ];

    public function up(): void
    {
        if (DB::table('roles')->exists()) {
            return;
        }

        foreach (self::ROLES as $name => $nameRu) {
            DB::table('roles')->insert([
                'name' => $name,
                'name_ru' => $nameRu,
                'guard_name' => 'api',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Роли могли получить пользователей и права — откат не удаляет их
    }
};
