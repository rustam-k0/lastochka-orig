<?php

declare(strict_types=1);

namespace App\Services\SlotsApi;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;

final class FakeSlotsApiClient implements SlotsApiClientInterface
{
    /**
     * @var array<int, array{id: int, code: string, delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges: array<int, array{from_amount: int, price: int}>}>
     */
    private array $shops = [];

    /** @var array<int, array<string, mixed>> */
    private array $cdekPvz = [];

    private ?array $cdekPickup = null;

    private bool $cdekPickupNotFound = false;

    /** @var array<int, array<string, mixed>> */
    private array $rpOffices = [];

    private ?array $rpOffice = null;

    private bool $rpOfficeNotFound = false;

    /** @var array<int, array<string, mixed>> */
    private array $pickupCities = [];

    /** @var array<int, mixed> */
    private array $cdekTariffs = [];

    /** @var array<int, mixed> */
    private array $rpTariffs = [];

    /** @var array<int, array{integration_type: string, is_active: bool}> */
    private array $shopIntegrationConfigs = [];

    private bool $shouldFail = false;

    /**
     * Установить магазины для возврата.
     *
     * @param  array<int, array{id: int, code: string, delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges: array<int, array{from_amount: int, price: int}>}>  $shops
     */
    public function setShops(array $shops): self
    {
        $this->shops = $shops;

        return $this;
    }

    /**
     * Установить ПВЗ CDEK для возврата.
     *
     * @param  array<int, array<string, mixed>>  $pvz
     */
    public function setCdekPvz(array $pvz): self
    {
        $this->cdekPvz = $pvz;

        return $this;
    }

    /**
     * Установить одну точку CDEK для возврата (или null для имитации 404).
     */
    public function setCdekPickup(?array $pickup, bool $notFound = false): self
    {
        $this->cdekPickup = $pickup;
        $this->cdekPickupNotFound = $notFound;

        return $this;
    }

    /**
     * Установить отделения Почты России для возврата.
     *
     * @param  array<int, array<string, mixed>>  $offices
     */
    public function setRpOffices(array $offices): self
    {
        $this->rpOffices = $offices;

        return $this;
    }

    /**
     * Установить одно отделение Почты России (или null для имитации 404).
     */
    public function setRpOffice(?array $office, bool $notFound = false): self
    {
        $this->rpOffice = $office;
        $this->rpOfficeNotFound = $notFound;

        return $this;
    }

    /**
     * Установить города для самовывоза.
     *
     * @param  array<int, array<string, mixed>>  $cities
     */
    public function setPickupCities(array $cities): self
    {
        $this->pickupCities = $cities;

        return $this;
    }

    /**
     * Установить тарифы CDEK для возврата.
     *
     * @param  array<int, mixed>  $tariffs
     */
    public function setCdekTariffs(array $tariffs): self
    {
        $this->cdekTariffs = $tariffs;

        return $this;
    }

    /**
     * Установить тарифы Почты России для возврата.
     *
     * @param  array<int, mixed>  $tariffs
     */
    public function setRpTariffs(array $tariffs): self
    {
        $this->rpTariffs = $tariffs;

        return $this;
    }

    /**
     * Установить конфигурации интеграций магазина.
     *
     * @param  array<int, array{integration_type: string, is_active: bool}>  $configs
     */
    public function setShopIntegrationConfigs(array $configs): self
    {
        $this->shopIntegrationConfigs = $configs;

        return $this;
    }

    /**
     * Установить режим ошибки.
     */
    public function setShouldFail(bool $shouldFail = true): self
    {
        $this->shouldFail = $shouldFail;

        return $this;
    }

    public function getAvailableShops(float $latitude, float $longitude): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->shops;
    }

    public function getCdekPvz(array $params): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->cdekPvz;
    }

    public function getCdekPickupByCode(string $code): ?array
    {
        if ($this->shouldFail || $this->cdekPickupNotFound) {
            return null;
        }

        return $this->cdekPickup;
    }

    public function getRpOffices(array $params): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->rpOffices;
    }

    public function getRpOfficeByIndex(string $index): ?array
    {
        if ($this->shouldFail || $this->rpOfficeNotFound) {
            return null;
        }

        return $this->rpOffice;
    }

    public function getPickupCities(?string $q, ?int $limit = null): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->pickupCities;
    }

    public function getCdekTariffs(array $params): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->cdekTariffs;
    }

    public function getRpTariffs(array $params): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->rpTariffs;
    }

    public function getShopIntegrationConfigs(int $shopId, array $types): array
    {
        if ($this->shouldFail) {
            return [];
        }

        return $this->shopIntegrationConfigs;
    }
}
