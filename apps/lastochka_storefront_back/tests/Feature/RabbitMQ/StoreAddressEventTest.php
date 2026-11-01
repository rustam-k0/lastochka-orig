<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\RabbitMQ\Transformers\StoreEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MENDEV-16: адрес магазина с обмена не сохранялся на витрине.
 * Админка не слала поле address, а трансформер витрины при отсутствии
 * ключа затирал уже сохранённый адрес в null.
 */
final class StoreAddressEventTest extends TestCase
{
    use RefreshDatabase;

    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'id' => '1',
            'source' => 'admin',
            'shop_code' => 'SHOP_'.Str::random(4),
            'title' => 'Магазин',
        ], $overrides);
    }

    public function test_address_is_applied_when_present(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent(
            $this->baseEvent(['address' => 'Красноярск, ул. Ленина 121']),
        );

        $this->assertSame('Красноярск, ул. Ленина 121', $attributes['address']);
    }

    public function test_missing_address_key_does_not_override_existing(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent($this->baseEvent());

        // Ключа нет в событии — атрибут не должен попасть в набор,
        // иначе update затрёт сохранённый адрес витрины в null
        $this->assertArrayNotHasKey('address', $attributes);
    }

    public function test_explicit_null_address_clears_value(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent(
            $this->baseEvent(['address' => null]),
        );

        // Явный null = адрес очистили в админке, применяем
        $this->assertArrayHasKey('address', $attributes);
        $this->assertNull($attributes['address']);
    }
}
