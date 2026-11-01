<?php

declare(strict_types=1);

namespace App\DTOs\Delivery;

use Carbon\Carbon;

final readonly class DeliverySlotData
{
    public function __construct(
        public int $id,
        public string $timeSlot,
        public Carbon $startAt,
        public Carbon $endAt,
    ) {}

    /**
     * Создать из ответа API.
     */
    public static function fromApiResponse(array $data): self
    {
        $start = Carbon::parse($data['start']);
        $end = Carbon::parse($data['end']);

        return new self(
            id: $start->timestamp,
            timeSlot: $start->format('H:i').'-'.$end->format('H:i'),
            startAt: $start,
            endAt: $end,
        );
    }

    /**
     * Создать из ID слота (timestamp).
     */
    public static function fromSlotId(int $slotId, int $durationHours = 2): self
    {
        $start = Carbon::createFromTimestamp($slotId, config('app.timezone'));
        $end = $start->copy()->addHours($durationHours);

        return new self(
            id: $slotId,
            timeSlot: $start->format('H:i').'-'.$end->format('H:i'),
            startAt: $start,
            endAt: $end,
        );
    }
}
