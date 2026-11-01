<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Address;
use App\Services\Store\AvailableStoreService;

final class AddressObserver
{
    public function __construct(
        private readonly AvailableStoreService $availableStoreService
    ) {}

    /**
     * При обновлении адреса инвалидировать кеш, если изменились координаты.
     */
    public function updated(Address $address): void
    {
        if ($address->wasChanged(['latitude', 'longitude'])) {
            $this->availableStoreService->invalidateCache($address->id);
        }
    }

    /**
     * При удалении адреса инвалидировать кеш.
     */
    public function deleted(Address $address): void
    {
        $this->availableStoreService->invalidateCache($address->id);
    }
}
