<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Order;

use App\Enums\OrderStatus;
use App\Services\Order\ClientOrderStatusAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для {@see ClientOrderStatusAggregator}.
 *
 * Покрывает правило "минимальный прогресс" (решение M.11=a, см.
 * docs/design-analysis/marketplace-model.md §5.1):
 *
 * - пустой массив → Pending (marketplace client_order только что создан, sub-orders ещё нет)
 * - все Cancelled → Cancelled
 * - хотя бы один non-cancelled не в Delivered → минимум (Pending < Assembling < Assembled < InDelivery < Delivered)
 * - все non-cancelled Delivered → Delivered (Cancelled sub-orders игнорируются)
 */
final class ClientOrderStatusAggregatorTest extends TestCase
{
    private ClientOrderStatusAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new ClientOrderStatusAggregator();
    }

    public function test_empty_statuses_returns_pending(): void
    {
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([])
        );
    }

    public function test_single_pending_returns_pending(): void
    {
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([OrderStatus::Pending])
        );
    }

    public function test_single_delivered_returns_delivered(): void
    {
        $this->assertSame(
            OrderStatus::Delivered,
            $this->aggregator->aggregate([OrderStatus::Delivered])
        );
    }

    public function test_single_cancelled_returns_cancelled(): void
    {
        $this->assertSame(
            OrderStatus::Cancelled,
            $this->aggregator->aggregate([OrderStatus::Cancelled])
        );
    }

    public function test_two_pending_returns_pending(): void
    {
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([OrderStatus::Pending, OrderStatus::Pending])
        );
    }

    public function test_pending_and_delivered_returns_pending(): void
    {
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([OrderStatus::Pending, OrderStatus::Delivered])
        );
    }

    public function test_assembling_and_delivered_returns_assembling(): void
    {
        $this->assertSame(
            OrderStatus::Assembling,
            $this->aggregator->aggregate([OrderStatus::Assembling, OrderStatus::Delivered])
        );
    }

    public function test_assembled_in_delivery_and_delivered_returns_assembled(): void
    {
        $this->assertSame(
            OrderStatus::Assembled,
            $this->aggregator->aggregate([
                OrderStatus::Assembled,
                OrderStatus::InDelivery,
                OrderStatus::Delivered,
            ])
        );
    }

    public function test_in_delivery_and_delivered_returns_in_delivery(): void
    {
        $this->assertSame(
            OrderStatus::InDelivery,
            $this->aggregator->aggregate([
                OrderStatus::InDelivery,
                OrderStatus::Delivered,
                OrderStatus::Delivered,
            ])
        );
    }

    public function test_all_delivered_returns_delivered(): void
    {
        $this->assertSame(
            OrderStatus::Delivered,
            $this->aggregator->aggregate([OrderStatus::Delivered, OrderStatus::Delivered])
        );
    }

    public function test_delivered_with_cancelled_returns_delivered(): void
    {
        // cancelled пропускается: effective = [Delivered, Delivered] → Delivered
        $this->assertSame(
            OrderStatus::Delivered,
            $this->aggregator->aggregate([
                OrderStatus::Delivered,
                OrderStatus::Delivered,
                OrderStatus::Cancelled,
            ])
        );
    }

    public function test_pending_with_cancelled_returns_pending(): void
    {
        // cancelled пропускается: effective = [Pending] → Pending
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([
                OrderStatus::Pending,
                OrderStatus::Cancelled,
            ])
        );
    }

    public function test_all_cancelled_returns_cancelled(): void
    {
        $this->assertSame(
            OrderStatus::Cancelled,
            $this->aggregator->aggregate([
                OrderStatus::Cancelled,
                OrderStatus::Cancelled,
            ])
        );
    }

    public function test_full_spectrum_returns_pending(): void
    {
        // Весь spectrum статусов → минимум = Pending
        $this->assertSame(
            OrderStatus::Pending,
            $this->aggregator->aggregate([
                OrderStatus::Pending,
                OrderStatus::Assembling,
                OrderStatus::Assembled,
                OrderStatus::InDelivery,
                OrderStatus::Delivered,
            ])
        );
    }
}
