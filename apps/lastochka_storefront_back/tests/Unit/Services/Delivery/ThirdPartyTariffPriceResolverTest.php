<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Enums\ShippingMethod;
use App\Services\Delivery\ThirdPartyTariffPriceResolver;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ThirdPartyTariffPriceResolverTest extends TestCase
{
    public function test_returns_price_for_cdek_tariff_by_code(): void
    {
        $client = new FakeSlotsApiClient();
        $client->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350, 'currency' => 'RUB'],
            ['code' => '138', 'name' => 'Склад-Дверь', 'price' => 450, 'currency' => 'RUB'],
        ]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        $price = $resolver->resolve(
            shopId: 1,
            shippingMethod: ShippingMethod::Cdek,
            externalId: 'MSK001',
            tariffCode: '138',
            weightGrams: 500,
        );

        $this->assertSame(450, $price);
    }

    public function test_returns_price_for_russian_post_tariff_by_code(): void
    {
        $client = new FakeSlotsApiClient();
        $client->setRpTariffs([
            ['code' => 'ONLINE_PARCEL_ORDINARY', 'name' => 'Посылка онлайн', 'price' => 280],
            ['code' => 'EMS_PARCEL', 'name' => 'EMS', 'price' => 720],
        ]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        $price = $resolver->resolve(
            shopId: 1,
            shippingMethod: ShippingMethod::RussianPost,
            externalId: '101000',
            tariffCode: 'EMS_PARCEL',
            weightGrams: 1500,
        );

        $this->assertSame(720, $price);
    }

    public function test_throws_422_when_tariff_not_found_in_response(): void
    {
        $client = new FakeSlotsApiClient();
        $client->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350],
        ]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        try {
            $resolver->resolve(
                shopId: 1,
                shippingMethod: ShippingMethod::Cdek,
                externalId: 'MSK001',
                tariffCode: '999',
                weightGrams: null,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shippingTariffCode', $e->errors());
        }
    }

    public function test_throws_422_when_provider_returned_empty_tariffs(): void
    {
        $client = new FakeSlotsApiClient();
        // Тарифов нет вообще — провайдер недоступен или нет тарифов до пункта.
        $client->setCdekTariffs([]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        $this->expectException(ValidationException::class);

        $resolver->resolve(
            shopId: 1,
            shippingMethod: ShippingMethod::Cdek,
            externalId: 'MSK001',
            tariffCode: '136',
            weightGrams: null,
        );
    }

    public function test_throws_422_when_external_id_missing(): void
    {
        $resolver = new ThirdPartyTariffPriceResolver(new FakeSlotsApiClient());

        try {
            $resolver->resolve(
                shopId: 1,
                shippingMethod: ShippingMethod::Cdek,
                externalId: null,
                tariffCode: '136',
                weightGrams: null,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address', $e->errors());
        }
    }

    public function test_throws_422_when_tariff_code_missing(): void
    {
        $client = new FakeSlotsApiClient();
        $client->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350],
        ]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        try {
            $resolver->resolve(
                shopId: 1,
                shippingMethod: ShippingMethod::Cdek,
                externalId: 'MSK001',
                tariffCode: null,
                weightGrams: null,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shippingTariffCode', $e->errors());
        }
    }

    public function test_rejects_non_pickup_point_method(): void
    {
        $resolver = new ThirdPartyTariffPriceResolver(new FakeSlotsApiClient());

        $this->expectException(\LogicException::class);

        $resolver->resolve(
            shopId: 1,
            shippingMethod: ShippingMethod::Courier,
            externalId: 'MSK001',
            tariffCode: '136',
            weightGrams: null,
        );
    }

    public function test_rounds_fractional_price_to_int(): void
    {
        $client = new FakeSlotsApiClient();
        $client->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350.4],
            ['code' => '138', 'name' => 'Склад-Дверь', 'price' => 450.7],
        ]);

        $resolver = new ThirdPartyTariffPriceResolver($client);

        $this->assertSame(350, $resolver->resolve(1, ShippingMethod::Cdek, 'MSK001', '136', null));
        $this->assertSame(451, $resolver->resolve(1, ShippingMethod::Cdek, 'MSK001', '138', null));
    }
}
