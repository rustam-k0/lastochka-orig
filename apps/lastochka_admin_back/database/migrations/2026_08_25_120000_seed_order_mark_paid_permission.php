<?php

use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Право «Отметка оплаты наличными» (orders.mark_paid) для матрицы доступов:
 * мутация OrderMarkPaid в purchase_back под @orderAction(key: "mark_paid").
 * admin/superadmin байпасят проверку, грант — для консистентности данных.
 *
 * Через DB::table, а не Permission::findOrCreate() (образец —
 * seed_roles_section_permission): findOrCreate тянет Spatie permission-кеш,
 * а storage/framework/cache/data на серверах создаёт php-fpm (www-data 755) —
 * CI-раннер туда писать не может, и миграция падала на stage с
 * `file_put_contents(...): Permission denied` (GlitchTip ADMIN_BACK-2U, #547).
 * insertOrIgnore идемпотентен на повторных прогонах.
 */
return new class extends Migration {
    private const GUARD = 'api';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => OrderAction::MARK_PAID,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = $this->permissionId();

        $roleIds = DB::table('roles')
            ->whereIn('name', [Role::ADMIN, Role::SUPERADMIN])
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = $this->permissionId();

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        $this->forgetCachedPermissions();
    }

    private function permissionId(): ?int
    {
        $id = DB::table('permissions')
            ->where('name', OrderAction::MARK_PAID)
            ->where('guard_name', self::GUARD)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Сброс permission-кеша не должен ронять миграцию: на части окружений
     * storage/framework/cache не writable для CI-раннера.
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
