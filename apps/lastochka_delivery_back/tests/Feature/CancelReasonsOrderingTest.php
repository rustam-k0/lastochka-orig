<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Причины отмены у сборщика должны выводиться в том же порядке, что и в
 * админке (Platrum ALADDIN-11977): position ASC, NULL-позиции в конце,
 * при равенстве — id ASC. Раньше @all отдавал в порядке вставки (по id),
 * и переставленная в админке причина у сборщика оставалась на старом месте.
 */
class CancelReasonsOrderingTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Справочники причин отдаются только авторизованному сотруднику (@guard).
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Collector', 'surname' => 'Причины ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(\App\Models\User::findOrFail($userId), 'api');
    }

    private function insertReason(string $table, string $title, ?int $position, bool $isActive = true): int
    {
        return \DB::table($table)->insertGetId([
            'title' => $title,
            'is_active' => $isActive,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertReasonsOrdered(string $table, string $queryField): void
    {
        // Позиции нарочно не совпадают с порядком вставки (id):
        // last (10) вставлена раньше, чем middle (6) — как в баге,
        // где «Новая причина» (id 6, поз. 10) шла раньше «Тест» (id 8, поз. 6).
        $lastId = $this->insertReason($table, 'Позиция 10', 10);
        $nullId = $this->insertReason($table, 'Без позиции', null);
        $middleId = $this->insertReason($table, 'Позиция 6', 6);
        $firstId = $this->insertReason($table, 'Позиция 1', 1);
        $this->insertReason($table, 'Неактивная', 2, false);

        $response = $this->graphQL(/** @lang GraphQL */ "
            { {$queryField} { id } }
        ");

        $ids = array_column($response->json("data.{$queryField}"), 'id');

        // Только вставленные (транзакция теста); в живой БД могут быть свои строки —
        // сравниваем взаимный порядок наших записей.
        $ours = array_values(array_intersect($ids, [$lastId, $nullId, $middleId, $firstId]));

        $this->assertSame([$firstId, $middleId, $lastId, $nullId], $ours);
    }

    public function test_product_cancel_reasons_ordered_by_position_nulls_last(): void
    {
        $this->assertReasonsOrdered('product_cancel_reasons', 'product_cancel_reasons');
    }

    public function test_order_cancel_reasons_ordered_by_position_nulls_last(): void
    {
        $this->assertReasonsOrdered('order_cancel_reasons', 'order_cancel_reasons');
    }

    public function test_inactive_reasons_are_hidden(): void
    {
        $inactiveId = $this->insertReason('product_cancel_reasons', 'Скрытая', 1, false);

        $response = $this->graphQL('{ product_cancel_reasons { id } }');

        $ids = array_column($response->json('data.product_cancel_reasons'), 'id');

        $this->assertNotContains($inactiveId, $ids);
    }
}
