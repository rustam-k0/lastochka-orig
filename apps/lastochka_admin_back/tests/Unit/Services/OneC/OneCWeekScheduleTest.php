<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OneC;

use App\Models\Type;
use App\Services\OneC\OneCWeekSchedule;
use PHPUnit\Framework\TestCase;

class OneCWeekScheduleTest extends TestCase
{
    public function test_null_is_empty_schedule(): void
    {
        $this->assertSame([], OneCWeekSchedule::parse(null));
    }

    public function test_parses_days_and_days_off(): void
    {
        $this->assertSame([
            Type::MONDAY => ['start' => '09:00', 'end' => '21:00'],
            Type::SUNDAY => null,
        ], OneCWeekSchedule::parse([
            'monday' => ['start' => '09:00', 'end' => '21:00'],
            'sunday' => null,
            'holiday' => ['start' => '10:00', 'end' => '11:00'],
        ]));
    }

    public function test_normalizes_time_formats(): void
    {
        $this->assertSame([
            Type::TUESDAY => ['start' => '09:00', 'end' => '21:30'],
        ], OneCWeekSchedule::parse(['tuesday' => ['start' => '9:00', 'end' => '21:30:00']]));
    }

    public function test_accepts_objects(): void
    {
        $schedule = json_decode('{"friday":{"start":"08:00","end":"23:00"}}');

        $this->assertSame([
            Type::FRIDAY => ['start' => '08:00', 'end' => '23:00'],
        ], OneCWeekSchedule::parse($schedule));
    }

    /** @dataProvider invalid */
    public function test_invalid_schedule_is_false(mixed $raw): void
    {
        $this->assertFalse(OneCWeekSchedule::parse($raw));
    }

    public static function invalid(): array
    {
        return [
            'string' => ['пн-пт 9-21'],
            'interval scalar' => [['monday' => '09:00-21:00']],
            'start after end' => [['monday' => ['start' => '22:00', 'end' => '06:00']]],
            'start equals end' => [['monday' => ['start' => '09:00', 'end' => '09:00']]],
            'hour 25' => [['monday' => ['start' => '25:00', 'end' => '26:00']]],
            'missing end' => [['monday' => ['start' => '09:00']]],
            'one bad day spoils all' => [[
                'monday' => ['start' => '09:00', 'end' => '21:00'],
                'tuesday' => ['start' => 'утро', 'end' => '21:00'],
            ]],
        ];
    }
}
