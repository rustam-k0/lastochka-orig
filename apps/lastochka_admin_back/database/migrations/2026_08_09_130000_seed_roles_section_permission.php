<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Право-раздел «Роли и доступы» (section.roles).
 *
 * Раздел стал колонкой матрицы доступов: суперадмин/админ может выдать его
 * созданной роли, и та получает доступ к управлению ролями (AdminPolicy::manageRoles).
 * Право обязано существовать заранее — SyncRoleSections зовёт
 * Permission::findOrCreate(), а тот на форках со stale permission-кешем
 * недетерминированно падает 1062 (см. миграцию seed_section_permissions).
 *
 * Через DB::table, а не Eloquent/Permission::findOrCreate: модели admin_back
 * висят под RabbitMQ-observer'ом (пишет в лог, недоступный CI-раннеру), а
 * findOrCreate читает stale-кеш. insertOrIgnore идемпотентен на повторных прогонах.
 *
 * Роль admin грант не получает намеренно: admin и superadmin байпасят проверку
 * разделов (UserFields::sectionKeys / SectionDirective), их строка в матрице
 * не редактируется.
 */
return new class extends Migration {
    private const PERMISSION = 'section.roles';
    private const GUARD = 'api';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        $this->forgetCachedPermissions();
    }

    /**
     * Сброс permission-кеша не должен ронять миграцию: на части окружений
     * storage/framework/cache не writable для CI-раннера (там кеш и так протух).
     */
    private function forgetCachedPermissions(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $e) {
            // no-op: кеш добьёт `php artisan permission:cache-reset` после деплоя
        }
    }
};
