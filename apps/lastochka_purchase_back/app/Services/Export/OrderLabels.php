<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Carbon\Carbon;

/**
 * Человекочитаемые подписи полей заказа для Excel-выгрузок.
 * Зеркалит то, что видит пользователь в таблице «Заказы» админки.
 */
class OrderLabels
{
    /** Кеш флага модуля checkout_without_slots на время жизни экспорта. */
    private ?bool $checkoutWithoutSlots = null;

    private const STATUS_LABELS = [
        Status::ORDER_CREATED => 'Создан',
        Status::ORDER_PREPARING => 'На сборке',
        Status::ORDER_ASSEMBLED => 'Собрано',
        Status::ORDER_IN_DELIVERY => 'В доставке',
        Status::ORDER_FINISHED => 'Доставлен',
        Status::ORDER_CANCELLED => 'Отменён',
    ];

    /**
     * Канон платформы: 1=карта, 2=наличные (Type::PAYMENT_CARD/PAYMENT_CASH).
     */
    private const PAYMENT_TYPE_LABELS = [
        1 => 'Карта',
        2 => 'Наличные',
    ];

    private const PAYMENT_STATUS_LABELS = [
        'paid' => 'Оплачено',
        'failed' => 'Ошибка оплаты',
        'refunded' => 'Возврат',
        'awaiting_surcharge' => 'Ожидает доплату',
    ];

    /**
     * Статус заказа. Завершённый самовывоз показывается как «Выдан», а
     * собранный заказ с назначенным курьером — как «Назначен курьер»
     * (только при включённом модуле courier_assignment) — так же, как
     * в таблице заказов админки.
     */
    public function status(Order $order, bool $hasCourier): string
    {
        if ((int) $order->status_id === Status::ORDER_FINISHED
            && (int) $order->type_id === Type::RECEIVE_PICKUP) {
            return 'Выдан';
        }

        if ((int) $order->status_id === Status::ORDER_ASSEMBLED
            && $hasCourier
            && Order::isCourierAssignmentModuleEnabled()) {
            return 'Назначен курьер';
        }

        return self::STATUS_LABELS[$order->status_id] ?? (string) $order->status_id;
    }

    public function deliveryType(?int $typeId): string
    {
        return $typeId === Type::RECEIVE_PICKUP ? 'Самовывоз' : 'Доставка';
    }

    public function paymentType(?int $paymentTypeId): string
    {
        return self::PAYMENT_TYPE_LABELS[$paymentTypeId] ?? (string) $paymentTypeId;
    }

    public function paymentStatus(?string $paymentStatus): string
    {
        return self::PAYMENT_STATUS_LABELS[$paymentStatus] ?? 'Ожидает оплаты';
    }

    /**
     * Бинарная подпись оплаты для листа по товарам — историческая колонка I,
     * менять её нельзя, на неё завязан OrderExportPaymentLabelTest.
     */
    public function paidFlag(Order $order): string
    {
        return $order->is_paid ? 'Оплачен' : 'Не оплачен';
    }

    public function slot(Order $order): string
    {
        if (!$order->slot_start_date || !$order->slot_end_date) {
            return '';
        }

        // Модуль «Оформление без слотов»: у доставки слот в заказе технический
        // (момент оформления), в экспорте не показываем. Самовывоз в модуль не
        // входит — клиент выбирает время выдачи, слот реальный.
        // Флаг читается один раз на экспорт.
        if ((int) $order->type_id !== Type::RECEIVE_PICKUP
            && ($this->checkoutWithoutSlots ??= Order::isCheckoutWithoutSlotsModuleEnabled())) {
            return '';
        }

        $start = Carbon::parse($order->slot_start_date);
        $end = Carbon::parse($order->slot_end_date);

        return $start->format('d.m.Y') . ', ' . $start->format('H:i') . ' — ' . $end->format('H:i');
    }

    public function customerName(Order $order): string
    {
        $clientInfo = $order->clientInfo;

        if (!$clientInfo) {
            return '';
        }

        return trim(($clientInfo->name ?? '') . ' ' . ($clientInfo->surname ?? ''));
    }

    public function customerPhone(Order $order): string
    {
        return $order->clientInfo->phone ?? ($order->client->phone ?? '');
    }

    /**
     * ФИО сотрудника в порядке «Фамилия Имя Отчество» — как в таблице заказов.
     */
    public function staffName(?object $staff): string
    {
        if (!$staff) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $staff->surname ?? null,
            $staff->name ?? null,
            $staff->patronymic ?? null,
        ])));
    }
}
