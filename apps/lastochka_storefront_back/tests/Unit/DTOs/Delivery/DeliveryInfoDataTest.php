<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use Tests\TestCase;

/**
 * Зонная стоимость сборки (assembly_price) из ответа slots-api.
 *
 * Контракт: /slots/available возвращает `assembly_price` — int|null.
 * null = у зоны нет своей цены ЛИБО пакет slots-api ещё не задеплоен →
 * потребитель падает на магазинную $store->assembly_price.
 */
final class DeliveryInfoDataTest extends TestCase
{
    public function test_from_api_response_parses_assembly_price_when_present(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'slots' => [],
            'min_order_price' => 500,
            'shipping_price' => 200,
            'shipping_free_from' => 2000,
            'assembly_price' => 150,
        ]);

        $this->assertSame(150, $dto->assemblyPrice);
    }

    public function test_from_api_response_casts_assembly_price_to_int(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
            'assembly_price' => '250',
        ]);

        $this->assertSame(250, $dto->assemblyPrice);
    }

    public function test_from_api_response_defaults_assembly_price_to_null_when_absent(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
        ]);

        $this->assertNull($dto->assemblyPrice);
    }

    public function test_from_api_response_keeps_assembly_price_null_when_explicitly_null(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
            'assembly_price' => null,
        ]);

        $this->assertNull($dto->assemblyPrice);
    }

    public function test_constructor_defaults_assembly_price_to_null(): void
    {
        $dto = new DeliveryInfoData(
            slots: [],
            minOrderAmount: 500,
            freeDeliveryFrom: null,
            deliveryPrice: 200,
        );

        $this->assertNull($dto->assemblyPrice);
    }
}
