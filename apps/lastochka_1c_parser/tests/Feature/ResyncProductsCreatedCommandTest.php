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
function makeProductForCreatedResync(array $attrs): Product
{
    return Product::factory()->withoutCategory()->create(array_merge(['shop_id' => 4], $attrs));
}

test('resync created payload содержит active (начальный статус для консьюмеров)', function () {
    makeProductForCreatedResync([
        'external_id' => 'guid-created-A',
        'name' => 'Новый товар',
        'is_active' => true,
    ]);

    EventOutbox::query()->delete();

    $exit = Artisan::call('1c:resync-products-created', [
        '--external-ids' => 'guid-created-A',
    ]);

    expect($exit)->toBe(0);
    expect(EventOutbox::count())->toBe(1);

    $event = EventOutbox::first();
    expect($event->event_type)->toBe('created')
        ->and($event->payload)->toHaveKey('active')
        ->and($event->payload['active'])->toBeTrue();
});
