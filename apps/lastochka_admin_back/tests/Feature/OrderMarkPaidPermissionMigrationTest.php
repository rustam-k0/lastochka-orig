<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Миграция 2026_08_25_120000_seed_order_mark_paid_permission.
 *
 * GlitchTip ADMIN_BACK-2U (#547): на stage миграция падала в CI с
 * `file_put_contents(storage/framework/cache/data/…): Permission denied` —
 * Permission::findOrCreate() тянет Spatie permission-кеш, а каталог кеша
 * создан php-fpm (www-data 755) и не writable для gitlab-runner.
 * Миграция не должна зависеть от файлового кеша вообще.
 */
class OrderMarkPaidPermissionMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = 'migrations/2026_08_25_120000_seed_order_mark_paid_permission.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Чистый старт внутри транзакции: локальная БД могла уже содержать право
        DB::table('permissions')->where('name', OrderAction::MARK_PAID)->delete();
    }

    public function test_up_creates_permission_and_grants_admin_and_superadmin(): void
    {
        $this->migration()->up();

        $permission = DB::table('permissions')
            ->where('name', OrderAction::MARK_PAID)
            ->where('guard_name', 'api')
            ->first();

        $this->assertNotNull($permission, 'Право orders.mark_paid не создано');

        $grantedRoles = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('role_has_permissions.permission_id', $permission->id)
            ->pluck('roles.name')
            ->all();

        $this->assertContains(Role::ADMIN, $grantedRoles, 'Роль admin не получила orders.mark_paid');
        $this->assertContains(Role::SUPERADMIN, $grantedRoles, 'Роль superadmin не получила orders.mark_paid');
    }

    public function test_up_is_idempotent(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            1,
            DB::table('permissions')->where('name', OrderAction::MARK_PAID)->count(),
            'Повторный up() задублировал право'
        );

        $permissionId = DB::table('permissions')->where('name', OrderAction::MARK_PAID)->value('id');
        $this->assertSame(
            2,
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->count(),
            'Повторный up() задублировал гранты ролям'
        );
    }

    public function test_up_survives_unwritable_permission_cache(): void
    {
        $this->useUnwritablePermissionCache();

        $this->migration()->up();

        $this->assertDatabaseHas('permissions', [
            'name' => OrderAction::MARK_PAID,
            'guard_name' => 'api',
        ]);
    }

    public function test_down_removes_permission_and_grants(): void
    {
        $this->migration()->up();
        $permissionId = DB::table('permissions')->where('name', OrderAction::MARK_PAID)->value('id');

        $this->migration()->down();

        $this->assertDatabaseMissing('permissions', ['name' => OrderAction::MARK_PAID]);
        $this->assertSame(0, DB::table('role_has_permissions')->where('permission_id', $permissionId)->count());
    }

    /**
     * Воспроизводит stage (GlitchTip #547): Spatie permission-кеш на файловом сторе,
     * каталог которого не writable для CI-раннера. FileStore::forget() при этом
     * молча возвращает false (Filesystem::delete глотает ошибку unlink), а
     * FileStore::put() бросает ErrorException из file_put_contents.
     */
    private function useUnwritablePermissionCache(): void
    {
        $store = new class extends ArrayStore {
            public function put($key, $value, $seconds): bool
            {
                throw new \ErrorException(
                    'file_put_contents(storage/framework/cache/data/85/5f/855f…): Failed to open stream: Permission denied'
                );
            }

            public function forget($key): bool
            {
                return false;
            }
        };

        Cache::extend('unwritable', fn ($app) => Cache::repository($store));
        config([
            'cache.stores.unwritable' => ['driver' => 'unwritable'],
            'permission.cache.store' => 'unwritable',
        ]);

        // singleton Spatie отдаёт заранее построенный экземпляр — перечитываем store через initializeCache()
        app(PermissionRegistrar::class)->initializeCache();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }
}
