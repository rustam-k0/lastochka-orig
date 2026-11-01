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

    /**
     * Разделы, которые роль shop видит по MenuItemSeeder — гранятся ей,
     * чтобы включение фича-флага не лишало мерчантов доступа.
     */
    private const SHOP_SECTIONS = [
        'orders', 'products', 'my_store', 'merchant-product-groups',
        'merchant-banners', 'merchant-promocodes', 'merchant-reviews',
        'product-reviews', 'settings',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::SECTIONS as $key) {
            $permissions[] = Permission::findOrCreate("section.$key");
        }

        $admin = Role::where('name', Role::ADMIN)->first();
        if ($admin) {
            $admin->givePermissionTo($permissions);
        }

        $shop = Role::where('name', Role::SHOP)->first();
        if ($shop) {
            $shop->givePermissionTo(array_map(
                fn ($key) => Permission::findOrCreate("section.$key"),
                self::SHOP_SECTIONS
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::SECTIONS as $key) {
            Permission::where('name', "section.$key")->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
