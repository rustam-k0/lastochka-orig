<?php

namespace Tests\Unit;

use App\Support\EventDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Даты в payload межсервисных событий (MID-272).
 *
 * Регрессия: издатель клал в payload сырой Carbon, json_encode сериализовал
 * его в UTC («2026-08-20T13:00:00.000000Z»), консьюмер присваивал строку
 * атрибуту с кастом datetime — и Laravel писал в колонку 13:00 вместо
 * московских 16:00. Каждое событие заказа уносило слот на 3 часа назад.
 */
class EventDateTimeTest extends TestCase
{
    public function test_payload_date_carries_explicit_offset(): void
    {
        $payload = EventDateTime::toPayload(Carbon::parse('2026-08-20 16:00:00', 'Europe/Moscow'));

        $this->assertSame('2026-08-20T16:00:00+03:00', $payload);
    }

    public function test_utc_payload_date_is_converted_to_app_timezone(): void
    {
        $restored = EventDateTime::fromPayload('2026-08-20T13:00:00.000000Z');

        $this->assertNotNull($restored);
        $this->assertTrue(
            $restored->equalTo(Carbon::parse('2026-08-20T13:00:00+00:00')),
            'момент должен сохраниться'
        );
        $this->assertSame(config('app.timezone'), $restored->getTimezone()->getName());
    }

    /**
     * Главная регрессия MID-272: до фикса каждый круг заказа через шину
     * уносил слот на офсет приложения назад (5 кругов = −15 часов).
     */
    public function test_repeated_payload_round_trips_do_not_shift_time(): void
    {
        $value = Carbon::parse('2026-08-20 16:00:00', config('app.timezone'));

        for ($i = 0; $i < 5; $i++) {
            // ровно то, что делает шина: payload уезжает в JSON и читается обратно
            $encoded = json_decode(
                json_encode(['slot_start_date' => EventDateTime::toPayload($value)]),
                true
            );
            $restored = EventDateTime::fromPayload($encoded['slot_start_date']);

            // и то, что делает Eloquent: в колонку без таймзоны кладётся стенное
            // время, а читается оно обратно уже в таймзоне приложения — здесь и
            // копился сдвиг, по 3 часа за круг
            $value = Carbon::parse($restored->format('Y-m-d H:i:s'), config('app.timezone'));
        }

        $this->assertSame('2026-08-20 16:00:00', $value->format('Y-m-d H:i:s'));
    }

    public function test_date_without_offset_stays_local(): void
    {
        $restored = EventDateTime::fromPayload('2026-08-20 16:00:00');

        $this->assertSame('2026-08-20 16:00:00', $restored->format('Y-m-d H:i:s'));
    }

    public function test_foreign_timezone_offset_is_converted_to_app_timezone(): void
    {
        // 1С отдаёт красноярское время: 20:00+07:00 = 16:00 в московской зоне
        $restored = EventDateTime::fromPayload('2026-08-20T20:00:00+07:00');

        $this->assertTrue($restored->equalTo(Carbon::parse('2026-08-20T13:00:00+00:00')));
        $this->assertSame(config('app.timezone'), $restored->getTimezone()->getName());
    }

    public function test_datetime_instance_is_accepted(): void
    {
        $payload = EventDateTime::toPayload(new \DateTimeImmutable('2026-08-20 16:00:00', new \DateTimeZone('Europe/Moscow')));

        $this->assertSame('2026-08-20T16:00:00+03:00', $payload);
    }

    public function test_empty_values_stay_null(): void
    {
        $this->assertNull(EventDateTime::toPayload(null));
        $this->assertNull(EventDateTime::toPayload(''));
        $this->assertNull(EventDateTime::fromPayload(null));
        $this->assertNull(EventDateTime::fromPayload(''));
    }

    public function test_broken_date_does_not_break_event_handling(): void
    {
        Log::shouldReceive('warning')->once();

        $this->assertNull(EventDateTime::fromPayload('не дата'));
    }

    public function test_source_instance_is_not_mutated(): void
    {
        $source = Carbon::parse('2026-08-20T13:00:00+00:00');
        $before = $source->format('Y-m-d H:i:sP');

        EventDateTime::fromPayload($source);
        EventDateTime::toPayload($source);

        $this->assertSame($before, $source->format('Y-m-d H:i:sP'));
    }
}
