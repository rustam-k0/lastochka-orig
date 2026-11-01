<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\User\ShiftFinish;
use App\Models\Shift;
use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Явное закрытие смены сотрудником (LASTOCHKI-JAMI-11595, #11599).
 *
 * Мутация ShiftFinish закрывает открытую смену текущего пользователя и
 * публикует shift.ended (сессия shift-<id>) через transactional outbox.
 */
class ShiftFinishTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Shift finish shop',
            'code' => 'shift-finish-shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openShift(): int
    {
        return \DB::table('shifts')->insertGetId([
            'user_id' => $this->userId,
            'shop_id' => $this->shopId,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
    }

    public function test_shift_finish_closes_shift_and_publishes_event(): void
    {
        $shiftId = $this->openShift();

        $this->actingAs(User::find($this->userId), 'api');

        $result = (new ShiftFinish())->__invoke(null, []);

        $this->assertSame(200, $result['code']);

        $finishedAt = \DB::table('shifts')->where('id', $shiftId)->value('finished_at');
        $this->assertNotNull($finishedAt, 'Смена не закрылась');

        $row = \DB::table('event_outbox')
            ->where('routing_key', 'shift.ended')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row, 'Событие shift.ended не попало в outbox');

        $payload = json_decode($row->payload, true);
        $this->assertSame('shift-' . $shiftId, $payload['session_id']);
        $this->assertSame($this->userId, $payload['employee_id']);
        // Смена открыта 3 часа назад — shift_seconds близок к 3 * 3600
        $this->assertEqualsWithDelta(3 * 3600, (int) $payload['shift_seconds'], 60);
    }

    public function test_shift_finish_without_open_shift_fails(): void
    {
        $this->actingAs(User::find($this->userId), 'api');

        $this->expectException(UserError::class);

        (new ShiftFinish())->__invoke(null, []);
    }

    public function test_detach_orders_closes_shift_with_event_before_soft_delete(): void
    {
        // Удаление юзера (REST /backend-delivery/user/delete → detachOrders)
        // раньше soft-delete'ило смену БЕЗ shift.ended — сессия зависала открытой
        $shiftId = $this->openShift();

        User::find($this->userId)->detachOrders();

        $row = \DB::table('shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row->finished_at, 'Смена не закрыта перед удалением');
        $this->assertNotNull($row->deleted_at, 'Смена не удалена (soft delete)');

        $outbox = \DB::table('event_outbox')
            ->where('routing_key', 'shift.ended')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($outbox, 'shift.ended не опубликован при удалении юзера');
        $this->assertSame('shift-' . $shiftId, json_decode($outbox->payload, true)['session_id']);
    }

    public function test_finish_shifts_command_still_publishes_events(): void
    {
        $shiftId = $this->openShift();

        Shift::finishShifts();

        $this->assertNotNull(
            \DB::table('shifts')->where('id', $shiftId)->value('finished_at'),
            'finishShifts не закрыл смену'
        );

        $row = \DB::table('event_outbox')
            ->where('routing_key', 'shift.ended')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('shift-' . $shiftId, json_decode($row->payload, true)['session_id']);
    }
}
