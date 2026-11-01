<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs\Delivery;

use App\DTOs\Delivery\DeliverySlotData;
use Carbon\Carbon;
use Tests\TestCase;

final class DeliverySlotDataTest extends TestCase
{
    public function test_from_api_response(): void
    {
        $data = [
            'start' => '2025-01-15 10:00:00',
            'end' => '2025-01-15 12:00:00',
        ];

        $slot = DeliverySlotData::fromApiResponse($data);

        $expectedTimestamp = Carbon::parse('2025-01-15 10:00:00')->timestamp;

        $this->assertEquals($expectedTimestamp, $slot->id);
        $this->assertEquals('10:00-12:00', $slot->timeSlot);
        $this->assertEquals('2025-01-15 10:00:00', $slot->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2025-01-15 12:00:00', $slot->endAt->format('Y-m-d H:i:s'));
    }

    public function test_from_api_response_afternoon(): void
    {
        $data = [
            'start' => '2025-01-15 14:30:00',
            'end' => '2025-01-15 16:30:00',
        ];

        $slot = DeliverySlotData::fromApiResponse($data);

        $this->assertEquals('14:30-16:30', $slot->timeSlot);
    }
}
