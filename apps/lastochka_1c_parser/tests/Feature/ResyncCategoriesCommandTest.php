<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\IntegrationConfig;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'djami_store');
    config()->set('rabbitmq-events.source', '1c');

    Shop::unguard();
    $this->djamiShop = Shop::create(['id' => 4]);
    $this->legacyShop = Shop::create(['id' => 1]);
    Shop::reguard();

    IntegrationConfig::create([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'credentials' => [],
    ]);
});

test('publishes only categories belonging to the resolved shop', function () {
    $root = Category::factory()->create([
        'shop_id' => 4,
        'name' => 'ЗАЛ',
        'parent_id' => null,
    ]);
    Category::factory()->create([
        'shop_id' => 4,
        'name' => 'БАКАЛЕЯ',
        'parent_id' => $root->id,
    ]);
    // Legacy дубль под другим shop_id — должен быть проигнорирован.
    Category::factory()->create([
        'shop_id' => 1,
        'name' => 'ЗАЛ-LEGACY',
        'parent_id' => null,
    ]);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('1c:resync-categories', [
        '--shop' => 'djami_store',
    ]);

    expect($exitCode)->toBe(0);
    expect(EventOutbox::count())->toBe(2);

    $events = EventOutbox::orderBy('id')->get();
    foreach ($events as $event) {
        expect($event->entity_type)->toBe('categories')
            ->and($event->event_type)->toBe('created')
            ->and($event->routing_key)->toBe('category_1c.created');
    }

    $names = $events->map(fn (EventOutbox $event) => $event->payload['title'])->all();
    expect($names)->toContain('ЗАЛ')->toContain('БАКАЛЕЯ')->not->toContain('ЗАЛ-LEGACY');
});

test('dry-run does not write to outbox', function () {
    Category::factory()->create([
        'shop_id' => 4,
        'name' => 'ТЕСТ',
        'parent_id' => null,
    ]);

    \Illuminate\Support\Facades\Artisan::call('1c:resync-categories', [
        '--shop' => 'djami_store',
        '--dry-run' => true,
    ]);

    expect(EventOutbox::count())->toBe(0);
});

test('publishes parents before children', function () {
    $root = Category::factory()->create([
        'shop_id' => 4,
        'name' => 'КУЛИНАРИЯ',
        'parent_id' => null,
    ]);
    $mid = Category::factory()->create([
        'shop_id' => 4,
        'name' => 'КОЛБАСНЫЕ ИЗДЕЛИЯ',
        'parent_id' => $root->id,
    ]);
    Category::factory()->create([
        'shop_id' => 4,
        'name' => 'Микоян',
        'parent_id' => $mid->id,
    ]);

    \Illuminate\Support\Facades\Artisan::call('1c:resync-categories', [
        '--shop' => 'djami_store',
    ]);

    $ordered = EventOutbox::orderBy('id')->get()
        ->map(fn (EventOutbox $event) => $event->payload['title'])
        ->all();
    expect($ordered)->toBe(['КУЛИНАРИЯ', 'КОЛБАСНЫЕ ИЗДЕЛИЯ', 'Микоян']);
});

test('only-roots flag publishes only root categories', function () {
    $root = Category::factory()->create([
        'shop_id' => 4,
        'name' => 'ЗАЛ',
        'parent_id' => null,
    ]);
    Category::factory()->create([
        'shop_id' => 4,
        'name' => 'БАКАЛЕЯ',
        'parent_id' => $root->id,
    ]);

    \Illuminate\Support\Facades\Artisan::call('1c:resync-categories', [
        '--shop' => 'djami_store',
        '--only-roots' => true,
    ]);

    expect(EventOutbox::count())->toBe(1);
    expect(EventOutbox::first()->payload['title'])->toBe('ЗАЛ');
});

test('fails when shop is not found', function () {
    $exitCode = \Illuminate\Support\Facades\Artisan::call('1c:resync-categories', [
        '--shop' => 'unknown_shop',
    ]);

    expect($exitCode)->not->toBe(0);
    expect(EventOutbox::count())->toBe(0);
});
