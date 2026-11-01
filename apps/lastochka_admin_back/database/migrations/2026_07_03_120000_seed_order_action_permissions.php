<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = array_map(
            fn (string $key) => Permission::findOrCreate($key),
            OrderAction::all()
        );

        // admin/superadmin байпасят проверки, гранты — для консистентности данных
        foreach ([Role::ADMIN, Role::SUPERADMIN] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        // Роль shop де-факто могла выгружать заказы (OrderExportController
        // разрешал SHOP, кнопки экспорта в UI не гейтились) — сохраняем при
        // включении матрицы. На mendev роли shop нет — защита форков.
        $shop = Role::where('name', Role::SHOP)->first();
        if ($shop) {
            $shop->givePermissionTo(Permission::findOrCreate(OrderAction::EXPORT));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (OrderAction::all() as $key) {
            Permission::where('name', $key)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
