<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderReview;
use PHPUnit\Framework\TestCase;

class OrderReviewOrderNumberTest extends TestCase
{
  public function test_order_number_is_order_uuid(): void
  {
    $order = new Order();
    $order->uuid = '123';

    $review = new OrderReview();
    $review->setRelation('order', $order);

    $this->assertSame('123', $review->order_number);
  }

  public function test_order_number_is_null_without_order(): void
  {
    $review = new OrderReview();
    $review->setRelation('order', null);

    $this->assertNull($review->order_number);
  }

  public function test_order_number_accessor_exists_on_order_model(): void
  {
    $order = new Order();
    $order->uuid = '5-1';

    $this->assertSame('5-1', $order->number);
  }
}
