<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OneCWeekSchedule;
use PHPUnit\Framework\TestCase;

final class OneCWeekScheduleTest extends TestCase
{
    public function test_null_and_empty_object_are_empty_schedule(): void
    {
        $this->assertSame([], OneCWeekSchedule::parse(null));
        $this->assertSame([], OneCWeekSchedule::parse([]));
    }

    public function test_parses_days_and_days_off(): void
    {
        $this->assertSame([
            1 => ['start' => '09:00', 'end' => '21:00'],
            7 => null,
        ], OneCWeekSchedule::parse([
            'monday' => ['start' => '09:00', 'end' => '21:00'],
            'sunday' => null,
            'holiday' => ['start' => '10:00', 'end' => '11:00'],
        ]));
    }

    public function test_normalizes_time_formats(): void
    {
        $this->assertSame([
            2 => ['start' => '09:00', 'end' => '21:30'],
        ], OneCWeekSchedule::parse(['tuesday' => ['start' => '9:00', 'end' => '21:30:00']]));
    }

    public function test_accepts_objects(): void
    {
        $schedule = json_decode('{"friday":{"start":"08:00","end":"23:00"}}');

        $this->assertSame([
            5 => ['start' => '08:00', 'end' => '23:00'],
        ], OneCWeekSchedule::parse($schedule));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
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
            'end at midnight' => [['monday' => ['start' => '09:00', 'end' => '00:00']]],
            'hour 25' => [['monday' => ['start' => '25:00', 'end' => '26:00']]],
            'missing end' => [['monday' => ['start' => '09:00']]],
            'one bad day spoils all' => [[
                'monday' => ['start' => '09:00', 'end' => '21:00'],
                'tuesday' => ['start' => 'утро', 'end' => '21:00'],
            ]],
        ];
    }
}
