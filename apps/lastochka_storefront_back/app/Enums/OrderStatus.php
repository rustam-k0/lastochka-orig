<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    /**
     * Черновик — заказ оформлен, но онлайн-оплата ещё не подтверждена
     * (модуль draft_orders_until_payment, redirect-флоу новой карты).
     *
     * Для клиента, курьерки, админки и 1С такого заказа не существует:
     * событие order.created не публикуется, корзина не очищается,
     * промокод не списывается. Переход в Pending — только после
     * подтверждения холда (FinalizeDraftOrderAction).
     */
    case Draft = 'draft';
    case Pending = 'pending';
    case Assembling = 'assembling';
    case Assembled = 'assembled';
    case InDelivery = 'inDelivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Статусы, в которых можно отменить заказ.
     *
     * @return array<self>
     */
    public static function cancellable(): array
    {
        return [
            self::Draft,
            self::Pending,
            self::Assembling,
        ];
    }

    /**
     * Можно ли отменить заказ в этом статусе.
     */
    public function canCancel(): bool
    {
        return in_array($this, self::cancellable(), true);
    }

    /**
     * Статусы, в которых можно дополнить заказ.
     *
     * @return array<self>
     */
    public static function supplementable(): array
    {
        return [
            self::Pending,
            self::Assembling,
        ];
    }

    /**
     * Можно ли дополнить заказ в этом статусе.
     */
    public function canSupplement(): bool
    {
        return in_array($this, self::supplementable(), true);
    }

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Ожидает оплаты',
            self::Pending => 'Ожидает обработки',
            self::Assembling => 'Собирается',
            self::Assembled => 'Собран',
            self::InDelivery => 'В доставке',
            self::Delivered => 'Доставлен',
            self::Cancelled => 'Отменён',
        };
    }
}
