<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * guard_name всех ролей и section.* permissions в этой БД — 'api'
     * (см. существующие строки roles/permissions), не 'web'.
     */
    private const GUARD = 'api';

    private const PERMISSION_NAME = 'section.pages';

    /**
     * Пункт меню админки для раздела «Страницы».
     * Базовый раздел — управляется через «Разделы меню» (is_visible),
     * без projectModule-тоггла (по образцу FAQ и вакансий).
     *
     * Вместе с пунктом создаётся permission section.pages и грантится
     * системным ролям admin/superadmin — при включённой матрице доступов
     * (role_access_matrix_enabled=1) SectionDirective пускает в раздел
     * только при наличии этого права у роли; без гранта раздел был бы
     * недостижим для admin/superadmin под матрицей.
     */
    public function up(): void
    {
        // Идемпотентность: если запись уже существует — пункт меню не трогаем.
        if (!DB::table('menu_items')->where('name', 'pages')->exists()) {
            // Встаём в конец списка, чтобы не сдвигать существующие пункты.
            $position = (int) DB::table('menu_items')->max('position') + 1;

            DB::table('menu_items')->insert([
                'name'       => 'pages',
                'title'      => 'Страницы',
                'icon'       => 'file-list-2-line',
                'position'   => $position,
                'is_visible' => 1,
                'accesses'   => json_encode(['admin']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Идемпотентность: permission могла быть создана раньше (повторный прогон,
        // ручное вмешательство на стенде) — не дублируем через DB::table (не Eloquent).
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION_NAME)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name'       => self::PERMISSION_NAME,
                'guard_name' => self::GUARD,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['admin', 'superadmin'] as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', self::GUARD)
                ->value('id');

            if ($roleId === null) {
                // Роль отсутствует на этом стенде — пропускаем, нечего гранить.
                continue;
            }

            $alreadyGranted = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (!$alreadyGranted) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id'       => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'pages')->delete();

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION_NAME)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
