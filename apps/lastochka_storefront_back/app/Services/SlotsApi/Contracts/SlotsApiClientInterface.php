<?php

declare(strict_types=1);

namespace App\Services\SlotsApi\Contracts;

interface SlotsApiClientInterface
{
    /**
     * Получить список магазинов, доступных для доставки по координатам.
     *
     * @return array<int, array{id: int, code: string, delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges: array<int, array{from_amount: int, price: int}>}>
     */
    public function getAvailableShops(float $latitude, float $longitude): array;

    /**
     * Получить список ПВЗ CDEK по параметрам bbox/поиска.
     *
     * @param  array{bbox?: string, q?: string|null, limit?: int|null, type?: string|null}  $params
     * @return array<int, array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>
     */
    public function getCdekPvz(array $params): array;

    /**
     * Получить одну точку CDEK по коду.
     *
     * @return array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}|null
     */
    public function getCdekPickupByCode(string $code): ?array;

    /**
     * Получить список отделений Почты России.
     *
     * @param  array{bbox?: string, q?: string|null, limit?: int|null, opsType?: string|null}  $params
     * @return array<int, array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>
     */
    public function getRpOffices(array $params): array;

    /**
     * Получить одно отделение Почты России по индексу.
     *
     * @return array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}|null
     */
    public function getRpOfficeByIndex(string $index): ?array;

    /**
     * Получить список городов для самовывоза.
     *
     * @return array<int, array{name: string, slug: string, region: string, zip: string, lat: float, lng: float}>
     */
    public function getPickupCities(?string $q, ?int $limit = null): array;

    /**
     * Получить тарифы CDEK для доставки.
     *
     * @param  array{code?: string, city?: string, shopId: int, weight?: int|null, dimensions?: string|null, declaredValue?: int|null}  $params
     * @return array<int, mixed>
     */
    public function getCdekTariffs(array $params): array;

    /**
     * Получить тарифы Почты России для доставки.
     *
     * @param  array{index?: string, weight?: int|null, shopId: int}  $params
     * @return array<int, mixed>
     */
    public function getRpTariffs(array $params): array;

    /**
     * Получить конфигурации интеграций магазина.
     *
     * @param  array<int, string>  $types  Например: ['delivery_cdek', 'delivery_russian_post']
     * @return array<int, array{integration_type: string, is_active: bool}>
     */
    public function getShopIntegrationConfigs(int $shopId, array $types): array;
}
