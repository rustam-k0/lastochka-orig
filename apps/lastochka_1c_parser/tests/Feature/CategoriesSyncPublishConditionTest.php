<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\IntegrationConfig;
use App\RabbitMQ\Conditions\CategoriesSyncPublishCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->condition = new CategoriesSyncPublishCondition();
});

test('returns true when integration config enables sync for this shop', function () {
    IntegrationConfig::create([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => true,
        'credentials' => [],
    ]);

    $category = Category::factory()->create(['shop_id' => 4]);

    expect(($this->condition)($category))->toBeTrue();
});

test('returns false when sync_categories_enabled is off for this shop', function () {
    IntegrationConfig::create([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => false,
        'credentials' => [],
    ]);

    $category = Category::factory()->create(['shop_id' => 4]);

    expect(($this->condition)($category))->toBeFalse();
});

test('returns false when no integration config exists for this shop', function () {
    $category = Category::factory()->create(['shop_id' => 4]);

    expect(($this->condition)($category))->toBeFalse();
});

test('returns false when category has no shop_id', function () {
    IntegrationConfig::create([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => true,
        'credentials' => [],
    ]);

    $category = new Category(['shop_id' => null]);

    expect(($this->condition)($category))->toBeFalse();
});

test('ignores sync flag when shop_id mismatches integration config', function () {
    // Конфиг только для shop_id=4. Категория из другого магазина (shop_id=1)
    // не должна считаться "разрешённой к публикации".
    IntegrationConfig::create([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => true,
        'credentials' => [],
    ]);

    $otherShopCategory = Category::factory()->create(['shop_id' => 1, 'name' => 'Other']);

    expect(($this->condition)($otherShopCategory))->toBeFalse();
});
