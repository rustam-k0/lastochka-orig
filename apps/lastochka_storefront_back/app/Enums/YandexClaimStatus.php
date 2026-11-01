<?php

declare(strict_types=1);

namespace App\Enums;

enum YandexClaimStatus: string
{
    case New = 'new';
    case Estimating = 'estimating';
    case ReadyForApproval = 'ready_for_approval';
    case Accepted = 'accepted';
    case PerformerLookup = 'performer_lookup';
    case PerformerDraft = 'performer_draft';
    case PerformerFound = 'performer_found';
    case PickupArrived = 'pickup_arrived';
    case ReadyForPickupConfirmation = 'ready_for_pickup_confirmation';
    case Pickuped = 'pickuped';
    case DeliveryArrived = 'delivery_arrived';
    case ReadyForDeliveryConfirmation = 'ready_for_delivery_confirmation';
    case PayWaiting = 'pay_waiting';
    case Delivered = 'delivered';
    case DeliveredFinish = 'delivered_finish';
    case Returning = 'returning';
    case ReturnArrived = 'return_arrived';
    case ReadyForReturnConfirmation = 'ready_for_return_confirmation';
    case ReturnedFinish = 'returned_finish';
    case Cancelled = 'cancelled';
    case CancelledWithPayment = 'cancelled_with_payment';
    case CancelledWithItemsOnHands = 'cancelled_with_items_on_hands';
    case CancelledByTaxi = 'cancelled_by_taxi';
    case PerformerNotFound = 'performer_not_found';
    case EstimatingFailed = 'estimating_failed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DeliveredFinish,
            self::ReturnedFinish,
            self::Cancelled,
            self::CancelledWithPayment,
            self::CancelledWithItemsOnHands,
            self::CancelledByTaxi,
            self::PerformerNotFound,
            self::Failed,
        ]);
    }

    public function isPickupArrivedOrLater(): bool
    {
        return in_array($this, [
            self::PickupArrived,
            self::ReadyForPickupConfirmation,
            self::Pickuped,
            self::DeliveryArrived,
            self::ReadyForDeliveryConfirmation,
            self::PayWaiting,
            self::Delivered,
            self::DeliveredFinish,
            self::Returning,
            self::ReturnArrived,
            self::ReadyForReturnConfirmation,
            self::ReturnedFinish,
            self::CancelledWithPayment,
            self::CancelledWithItemsOnHands,
        ]);
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Estimating => 'Оценка',
            self::ReadyForApproval => 'Ожидает подтверждения',
            self::Accepted => 'Подтверждена',
            self::PerformerLookup => 'Поиск курьера',
            self::PerformerDraft => 'Черновик курьера',
            self::PerformerFound => 'Курьер найден',
            self::PickupArrived => 'Курьер в магазине',
            self::ReadyForPickupConfirmation => 'Ожидает передачи',
            self::Pickuped => 'Забрано',
            self::DeliveryArrived => 'Курьер у получателя',
            self::ReadyForDeliveryConfirmation => 'Ожидает получения',
            self::PayWaiting => 'Ожидание оплаты',
            self::Delivered => 'Доставлено',
            self::DeliveredFinish => 'Доставка завершена',
            self::Returning => 'Возврат',
            self::ReturnArrived => 'Курьер вернулся',
            self::ReadyForReturnConfirmation => 'Ожидает подтверждения возврата',
            self::ReturnedFinish => 'Возврат завершён',
            self::Cancelled => 'Отменена',
            self::CancelledWithPayment => 'Отменена (со штрафом)',
            self::CancelledWithItemsOnHands => 'Отменена (товар у курьера)',
            self::CancelledByTaxi => 'Отменена курьером',
            self::PerformerNotFound => 'Курьер не найден',
            self::EstimatingFailed => 'Ошибка оценки',
            self::Failed => 'Ошибка',
        };
    }
}
