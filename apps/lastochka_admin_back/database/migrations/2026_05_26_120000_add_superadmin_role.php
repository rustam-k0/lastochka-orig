<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const SECTIONS = [
        'orders', 'products', 'categories', 'clients', 'staffs', 'stores',
        'my_store', 'stories', 'merchant-sales', 'merchant-product-groups',
        'merchant-banners', 'merchant-promocodes', 'announcements',
        'merchant-reviews', 'product-reviews', 'settings',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var Role $super */
        $super = Role::firstOrCreate(
            ['name' => Role::SUPERADMIN],
            ['name_ru' => 'Супер-администратор', 'scope_type' => Role::SCOPE_GLOBAL, 'is_system' => true]
        );
        $super->scope_type = Role::SCOPE_GLOBAL;
        $super->is_system = true;
        $super->save();

        $super->givePermissionTo(array_map(
            fn ($key) => Permission::findOrCreate("section.$key"),
            self::SECTIONS
        ));

        // Бутстрап: текущие админы получают superadmin, чтобы не потерять
        // доступ к Настройкам. Дальнейшая выдача супер-админа — только через БД.
        $admin = Role::where('name', Role::ADMIN)->first();
        if ($admin) {
            foreach ($admin->users as $user) {
                $user->assignRole($super);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $super = Role::where('name', Role::SUPERADMIN)->first();
        if ($super) {
            $super->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
