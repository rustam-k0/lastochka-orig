<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DeliveryReturn;
use PHPUnit\Framework\TestCase;

/**
 * Чистый юнит-тест математики и валидации возврата товара курьером (tg-14265).
 * Без БД/Laravel (голый PHPUnit) — безопасно для prod-БД мины delivery_back.
 */
final class DeliveryReturnTest extends TestCase
{
    public function test_is_weighted_by_product_card(): void
    {
        // Признак берётся из карточки товара, вес позиции роли не играет
        $this->assertTrue(DeliveryReturn::isWeighted(true, null));
        $this->assertTrue(DeliveryReturn::isWeighted(true, 1000));
        $this->assertFalse(DeliveryReturn::isWeighted(false, null));
    }

    public function test_is_weighted_ignores_stuck_actual_weight_on_piece_product(): void
    {
        // MID-246: залипший actual_weight на штучном товаре не делает его весовым
        $this->assertFalse(DeliveryReturn::isWeighted(false, 200));
    }

    public function test_is_weighted_falls_back_to_actual_weight_without_product(): void
    {
        // Товар позиции недоступен — остаётся старый признак
        $this->assertTrue(DeliveryReturn::isWeighted(null, 1000));
        $this->assertFalse(DeliveryReturn::isWeighted(null, null));
    }

    public function test_weighted_item_without_actual_weight_returns_fully(): void
    {
        // Прод-кейс 2026-09-11: весовая колбаса собрана без взвешивания
        // (actual_weight NULL, quantity 1) — курьер не может её вернуть.
        $isWeighted = DeliveryReturn::isWeighted(true, null);
        $collected = DeliveryReturn::collectedPortions(1, null, 1000);

        $this->assertNull(DeliveryReturn::validateReturn($isWeighted, null, 0, $collected));
        $this->assertSame(1, DeliveryReturn::resolveReturnedPortions($isWeighted, null, 0, $collected));
        $this->assertSame(0.0, DeliveryReturn::deliveredLineTotal(446.0, null, 1000, $collected, 1));
    }

    public function test_collected_portions_piece(): void
    {
        // штучный: 3 шт, step=1 → 3 порции
        $this->assertSame(3, DeliveryReturn::collectedPortions(3, null, 1));
    }

    public function test_collected_portions_weighted(): void
    {
        // весовой: 1000 г при step=250 → 4 порции
        $this->assertSame(4, DeliveryReturn::collectedPortions(null, 1000, 250));
    }

    public function test_validate_weighted_partial_rejected(): void
    {
        // весовой: передан quantity (частичный) → ошибка
        $msg = DeliveryReturn::validateReturn(true, 2, 0, 4);
        $this->assertSame('Весовой товар можно вернуть только полностью', $msg);
    }

    public function test_validate_weighted_full_ok(): void
    {
        // весовой: quantity не передан → полный возврат, ок
        $this->assertNull(DeliveryReturn::validateReturn(true, null, 0, 4));
    }

    public function test_validate_piece_requires_quantity(): void
    {
        $this->assertSame('Укажите количество для возврата', DeliveryReturn::validateReturn(false, null, 0, 3));
    }

    public function test_validate_piece_out_of_range(): void
    {
        // доступно 3, уже вернули 1 → можно ещё 2; запрос 3 → ошибка
        $this->assertSame('Количество превышает доступное к возврату', DeliveryReturn::validateReturn(false, 3, 1, 3));
        $this->assertNull(DeliveryReturn::validateReturn(false, 2, 1, 3));
    }

    public function test_resolve_returned_portions_weighted_is_full(): void
    {
        $this->assertSame(4, DeliveryReturn::resolveReturnedPortions(true, null, 0, 4));
    }

    public function test_resolve_returned_portions_piece_accumulates(): void
    {
        // уже вернули 1, возвращаем ещё 2 → 3
        $this->assertSame(3, DeliveryReturn::resolveReturnedPortions(false, 2, 1, 3));
    }

    public function test_delivered_line_total_piece(): void
    {
        // 39₽/шт, собрано 3, вернули 1 → доставлено 2 → 78
        $this->assertSame(78.0, DeliveryReturn::deliveredLineTotal(39.0, null, 1, 3, 1));
    }

    public function test_delivered_line_total_weighted_full_return_is_zero(): void
    {
        $this->assertSame(0.0, DeliveryReturn::deliveredLineTotal(39.0, 1000, 250, 4, 4));
    }

    public function test_delivered_line_total_weighted_not_returned(): void
    {
        // 39₽/кг (step 1000), 1000г, не возвращали → 39
        $this->assertSame(39.0, DeliveryReturn::deliveredLineTotal(39.0, 1000, 1000, 1, 0));
    }
}
