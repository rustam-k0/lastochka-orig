<?php

declare(strict_types=1);

namespace App\Services\Pickup;

use App\Models\Store;
use App\Models\StoreSchedule;
use Carbon\Carbon;

final class PickupSlotService
{
    /**
     * Сгенерировать слоты самовывоза для магазина на указанную дату.
     *
     * @return array<array{id: int, timeSlot: string}>
     */
    public function getSlots(Store $store, string $date, ?Carbon $readyFrom = null): array
    {
        $store->loadMissing('schedules');

        $duration = $store->slot_duration ?? 60;

        [$openHour, $openMinute, $closeHour, $closeMinute] = $this->resolveWorkingHours($store, $date);

        if ($openHour === null) {
            return [];
        }

        $targetDate = Carbon::parse($date);
        $now = now();
        $isToday = $targetDate->isSameDay($now);

        $cursor = $targetDate->copy()->setTime($openHour, $openMinute, 0);
        $closeTime = $targetDate->copy()->setTime($closeHour, $closeMinute, 0);

        // Для 24/7 закрытие в 00:00 следующего дня — сдвигаем на +1 день
        if ($closeHour === 0 && $closeMinute === 0) {
            $closeTime->addDay();
        }

        $slots = [];

        while ($cursor->lt($closeTime)) {
            $slotEnd = $cursor->copy()->addMinutes($duration);

            // Обрезаем слот, если он выходит за конец рабочего дня
            if ($slotEnd->gt($closeTime)) {
                break;
            }

            // Для сегодняшнего дня пропускаем прошедшие слоты
            if ($isToday && $cursor->lt($now)) {
                $cursor->addMinutes($duration);
                continue;
            }

            // Готовность товаров (модуль product_ready_time): слот не может
            // начинаться раньше максимального момента готовности корзины.
            if ($readyFrom !== null && $cursor->lt($readyFrom)) {
                $cursor->addMinutes($duration);
                continue;
            }

            $slots[] = [
                'id'       => $cursor->timestamp,
                'timeSlot' => $cursor->format('H:i') . '–' . $slotEnd->format('H:i'),
            ];

            $cursor->addMinutes($duration);
        }

        return $slots;
    }

    /**
     * Определить часы работы магазина на указанную дату.
     *
     * @return array{int|null, int|null, int|null, int|null}
     */
    private function resolveWorkingHours(Store $store, string $date): array
    {
        if ($store->is_open_24_7) {
            return [0, 0, 0, 0]; // 00:00–00:00 следующего дня (24h)
        }

        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso; // 1=Mon, 7=Sun

        /** @var StoreSchedule|null $schedule */
        $schedule = $store->schedules->firstWhere('day_of_week', $dayOfWeek);

        if (! $schedule || ! $schedule->opens_at) {
            return [null, null, null, null];
        }

        return [
            (int) $schedule->opens_at->format('H'),
            (int) $schedule->opens_at->format('i'),
            (int) $schedule->closes_at->format('H'),
            (int) $schedule->closes_at->format('i'),
        ];
    }
}
