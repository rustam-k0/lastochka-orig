<?php

declare(strict_types=1);

namespace App\Enums;

enum ShippingMethod: string
{
    case Courier = 'courier';
    case Pickup = 'pickup';
    case YandexDelivery = 'yandex_delivery';
    case Cdek = 'cdek';
    case RussianPost = 'russian_post';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Courier => 'Курьер',
            self::Pickup => 'Самовывоз',
            self::YandexDelivery => 'Яндекс Доставка',
            self::Cdek => 'СДЭК',
            self::RussianPost => 'Почта России',
        };
    }

    /**
     * Методы доставки через пункты выдачи (ПВЗ).
     */
    public function isPickupPoint(): bool
    {
        return in_array($this, [self::Cdek, self::RussianPost], true);
    }
}
