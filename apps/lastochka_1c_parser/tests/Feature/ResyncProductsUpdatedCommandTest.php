<?php

declare(strict_types=1);

use App\Models\Product;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'djami_store');
    config()->set('rabbitmq-events.source', '1c');
});

/**
 * @param array<string, mixed> $attrs
 */
function makeProductForResync(array $attrs): Product
{
    return Product::factory()->withoutCategory()->create(array_merge(['shop_id' => 4], $attrs));
}

test('публикует product.updated для указанных external_ids', function () {
    makeProductForResync(['external_id' => 'guid-A', 'name' => 'Масло слив городень']);
    makeProductForResync(['external_id' => 'guid-B', 'name' => 'Котлеты']);
    makeProductForResync(['external_id' => 'guid-C', 'name' => 'Не трогаем']);

    EventOutbox::query()->delete();

    $exit = Artisan::call('1c:resync-products-updated', [
        '--external-ids' => 'guid-A,guid-B',
    ]);

    expect($exit)->toBe(0);
    expect(EventOutbox::count())->toBe(2);

    $events = EventOutbox::orderBy('id')->get();
    foreach ($events as $event) {
        expect($event->entity_type)->toBe('products')
            ->and($event->event_type)->toBe('updated')
            ->and($event->routing_key)->toBe('product.updated');
    }

    $titles = $events->map(fn (EventOutbox $e) => $e->payload['title'])->all();
    expect($titles)->toContain('Масло слив городень')->toContain('Котлеты')->not->toContain('Не трогаем');
});

test('dry-run ничего не пишет в outbox', function () {
    makeProductForResync(['external_id' => 'guid-A', 'name' => 'Тест']);

    EventOutbox::query()->delete();


    Artisan::call('1c:resync-products-updated', [
        '--external-ids' => 'guid-A',
        '--dry-run' => true,
    ]);

    expect(EventOutbox::count())->toBe(0);
});

test('--all публикует все товары', function () {
    makeProductForResync(['external_id' => 'guid-A']);
    makeProductForResync(['external_id' => 'guid-B']);
    makeProductForResync(['external_id' => 'guid-C']);

    EventOutbox::query()->delete();


    Artisan::call('1c:resync-products-updated', ['--all' => true]);

    expect(EventOutbox::count())->toBe(3);
    expect(EventOutbox::where('routing_key', 'product.updated')->count())->toBe(3);
});

test('проставляет свежий event_timestamp (иначе consumer отбросит как outdated)', function () {
    makeProductForResync(['external_id' => 'guid-A', 'name' => 'Тест']);

    EventOutbox::query()->delete();


    Artisan::call('1c:resync-products-updated', ['--external-ids' => 'guid-A']);

    $payload = EventOutbox::first()->payload;
    expect($payload)->toHaveKey('event_timestamp');
    expect(\Illuminate\Support\Carbon::parse($payload['event_timestamp'])->diffInMinutes(now()))->toBeLessThan(2);
});

test('читает external_id из файла (--ids-file)', function () {
    makeProductForResync(['external_id' => 'guid-A', 'name' => 'A']);
    makeProductForResync(['external_id' => 'guid-B', 'name' => 'B']);

    $file = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($file, "guid-A\nguid-B\n");

    EventOutbox::query()->delete();


    Artisan::call('1c:resync-products-updated', ['--ids-file' => $file]);
    @unlink($file);

    expect(EventOutbox::count())->toBe(2);
});

test('без аргументов возвращает ошибку', function () {
    $exit = Artisan::call('1c:resync-products-updated', []);

    expect($exit)->not->toBe(0);
    expect(EventOutbox::count())->toBe(0);
});

test('resync updated payload НЕ содержит active (1С не управляет витринной активностью)', function () {
    makeProductForResync([
        'external_id' => 'guid-no-active',
        'name' => 'Обновляемый товар',
        'is_active' => true,
    ]);

    EventOutbox::query()->delete();

    Artisan::call('1c:resync-products-updated', [
        '--external-ids' => 'guid-no-active',
    ]);

    expect(EventOutbox::count())->toBe(1);
    expect(EventOutbox::first()->payload)->not->toHaveKey('active');
});
