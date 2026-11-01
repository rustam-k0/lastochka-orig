<?php

declare(strict_types=1);

use App\Models\IntegrationConfig;
use App\Models\User;
use App\RabbitMQ\Transformers\IntegrationConfigEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', false);
});

test('observer создаёт User в users при сохранении 1c IntegrationConfig', function () {
    expect(User::count())->toBe(0);

    IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'credentials' => ['login' => 'test_1c_user', 'password' => 'secret_pass'],
        'is_active' => true,
        'shop_id' => 1,
        'shop_code' => 'main',
    ]);

    $user = User::where('email', 'test_1c_user')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('1C Exchange User');
    expect(Hash::check('secret_pass', $user->password))->toBeTrue();
});

test('observer обновляет пароль существующего User при смене credentials', function () {
    $config = IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'credentials' => ['login' => 'persistent_user', 'password' => 'old_pass'],
        'is_active' => true,
        'shop_id' => 1,
        'shop_code' => 'main',
    ]);

    $config->update([
        'credentials' => ['login' => 'persistent_user', 'password' => 'new_pass'],
    ]);

    $user = User::where('email', 'persistent_user')->first();

    expect(User::count())->toBe(1);
    expect(Hash::check('new_pass', $user->password))->toBeTrue();
    expect(Hash::check('old_pass', $user->password))->toBeFalse();
});

test('observer не трогает users для не-1c интеграций', function () {
    IntegrationConfig::create([
        'integration_type' => 'retail',
        'driver' => 'retailcrm',
        'credentials' => ['login' => 'retail_user', 'password' => 'retail_pass'],
        'is_active' => true,
        'shop_id' => 1,
        'shop_code' => 'main',
    ]);

    expect(User::count())->toBe(0);
});

test('observer пропускает запись с пустыми credentials', function () {
    IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'credentials' => [],
        'is_active' => false,
        'shop_id' => 1,
        'shop_code' => 'main',
    ]);

    expect(User::count())->toBe(0);
});

test('artisan 1c:sync-user-credentials синкает существующие записи в users', function () {
    IntegrationConfig::withoutEvents(function () {
        IntegrationConfig::create([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => ['login' => 'legacy_user', 'password' => 'legacy_pass'],
            'is_active' => true,
            'shop_id' => 1,
            'shop_code' => 'main',
        ]);
    });

    expect(User::count())->toBe(0);

    $this->artisan('1c:sync-user-credentials')
        ->assertSuccessful()
        ->expectsOutputToContain('legacy_user');

    $user = User::where('email', 'legacy_user')->first();

    expect($user)->not->toBeNull();
    expect(Hash::check('legacy_pass', $user->password))->toBeTrue();
});

test('IntegrationConfigEventTransformer::afterCreate синкает users (RabbitMQ-путь от курьерки)', function () {
    // Эмулируем то, что делает EventConsumer: save через withoutEvents (observer не сработает)
    // → потом дёргается afterCreate. Это главный production-путь.
    $config = IntegrationConfig::withoutEvents(function () {
        return IntegrationConfig::create([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => ['login' => 'rabbit_user', 'password' => 'rabbit_pass'],
            'is_active' => true,
            'shop_id' => 1,
            'shop_code' => 'main',
        ]);
    });

    expect(User::count())->toBe(0);

    (new IntegrationConfigEventTransformer())->afterCreate($config, []);

    $user = User::where('email', 'rabbit_user')->first();
    expect($user)->not->toBeNull();
    expect(Hash::check('rabbit_pass', $user->password))->toBeTrue();
});

test('IntegrationConfigEventTransformer::afterUpdate синкает users (RabbitMQ-путь от курьерки)', function () {
    $config = IntegrationConfig::withoutEvents(function () {
        return IntegrationConfig::create([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => ['login' => 'rabbit_user', 'password' => 'old_pass'],
            'is_active' => true,
            'shop_id' => 1,
            'shop_code' => 'main',
        ]);
    });

    IntegrationConfig::withoutEvents(fn () => $config->update([
        'credentials' => ['login' => 'rabbit_user', 'password' => 'new_pass'],
    ]));

    (new IntegrationConfigEventTransformer())->afterUpdate($config->fresh(), []);

    $user = User::where('email', 'rabbit_user')->first();
    expect(Hash::check('new_pass', $user->password))->toBeTrue();
    expect(Hash::check('old_pass', $user->password))->toBeFalse();
});

test('artisan 1c:sync-user-credentials пропускает inactive', function () {
    IntegrationConfig::withoutEvents(function () {
        IntegrationConfig::create([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => ['login' => 'inactive_user', 'password' => 'inactive_pass'],
            'is_active' => false,
            'shop_id' => 1,
            'shop_code' => 'main',
        ]);
    });

    $this->artisan('1c:sync-user-credentials')->assertSuccessful();

    expect(User::where('email', 'inactive_user')->exists())->toBeFalse();
});
