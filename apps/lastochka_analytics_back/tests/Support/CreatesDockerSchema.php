<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тестовый двойник docker-БД: connection 'docker' переключается на
 * testing-Postgres, минимальная схема (users/tokens/roles/shops/shifts)
 * создаётся заново — RefreshDatabase стирает её вместе с остальными
 * таблицами тестовой БД.
 */
trait CreatesDockerSchema
{
    protected function setUpDockerSchema(): void
    {
        // Префикс docker_ разводит тестовый двойник с таблицами самого
        // analytics_back (users уже создаёт дефолтная миграция Laravel).
        config([
            'database.connections.docker' => array_merge(
                config('database.connections.'.config('database.default')),
                ['prefix' => 'docker_'],
            ),
        ]);

        DB::purge('docker');

        $schema = Schema::connection('docker');

        if ($schema->hasTable('personal_access_tokens')) {
            return;
        }

        $schema->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('surname')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        // Как в реальной docker-БД (Sanctum v2, миграция 2019 года):
        // колонки expires_at НЕТ — resolver обязан это переживать.
        $schema->create('personal_access_tokens', function ($table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        $schema->create('roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('api');
        });

        $schema->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        $schema->create('shops', function ($table): void {
            $table->id();
            $table->string('code')->nullable();
            $table->string('title')->nullable();
        });

        $schema->create('user_shops', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('shop_id');
            $table->timestamp('deleted_at')->nullable();
        });

        $schema->create('shifts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Юзер + Sanctum-токен. Возвращает [userId, bearer].
     *
     * @param  list<string>  $roles
     * @param  list<string>  $shopCodes
     * @return array{0: int, 1: string}
     */
    protected function createStaffWithToken(array $roles = ['collector'], array $shopCodes = ['shop-a'], string $name = 'Тест Сотрудников'): array
    {
        $docker = DB::connection('docker');

        [$firstName, $surname] = array_pad(explode(' ', $name, 2), 2, null);

        $userId = (int) $docker->table('users')->insertGetId([
            'name' => $firstName,
            'surname' => $surname,
        ]);

        foreach ($roles as $role) {
            $roleId = $docker->table('roles')->where('name', $role)->value('id')
                ?? $docker->table('roles')->insertGetId(['name' => $role, 'guard_name' => 'api']);

            $docker->table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ]);
        }

        foreach ($shopCodes as $code) {
            $shopId = $docker->table('shops')->where('code', $code)->value('id')
                ?? $docker->table('shops')->insertGetId(['code' => $code, 'title' => $code]);

            $docker->table('user_shops')->insert([
                'user_id' => $userId,
                'shop_id' => $shopId,
            ]);
        }

        $plain = bin2hex(random_bytes(20));

        $tokenId = (int) $docker->table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $userId,
            'name' => 'test',
            'token' => hash('sha256', $plain),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$userId, "{$tokenId}|{$plain}"];
    }

    protected function openShift(int $userId, int $shopId = 1): int
    {
        return (int) DB::connection('docker')->table('shifts')->insertGetId([
            'user_id' => $userId,
            'shop_id' => $shopId,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }
}
