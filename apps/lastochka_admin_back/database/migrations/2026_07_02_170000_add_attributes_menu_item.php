<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Пункт меню админки «Атрибуты» (/admin/attributes).
     *
     * Экран атрибутов существует во фронте с самого начала, но в menu_items
     * пункт никогда не сеялся (MenuItemSeeder его не содержит) — раздел был
     * недостижим из сайдбара, только по прямому URL.
     *
     * Вместе с пунктом создаётся permission section.attributes и грантится
     * системным ролям admin/superadmin — при включённой матрице доступов
     * (role_access_matrix_enabled=1) сайдбар показывает пункт только при
     * наличии section-права у роли, без гранта раздел остался бы невидимым.
     */
    public function up(): void
    {
        // Идемпотентность: на стендах, где пункт уже добавлен вручную — пропускаем.
        if (! DB::table('menu_items')->where('name', 'attributes')->exists()) {
            // Встаём в конец списка, чтобы не сдвигать существующие пункты.
            $position = (int) DB::table('menu_items')->max('position') + 1;

            DB::table('menu_items')->insert([
                'name'       => 'attributes',
                'title'      => 'Атрибуты',
                'icon'       => 'list-settings-line',
                'position'   => $position,
                'is_visible' => 1,
                'accesses'   => json_encode(['admin']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('section.attributes');

        foreach ([Role::ADMIN, Role::SUPERADMIN] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'attributes')->delete();
        Permission::where('name', 'section.attributes')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
