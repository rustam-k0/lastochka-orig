<?php

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderNumberTest extends TestCase
{
  public function test_number_is_uuid_for_regular_order(): void
  {
    $order = new Order();
    $order->uuid = '123';

    $this->assertSame('123', $order->number);
    $this->assertSame('123', $order->label);
  }

  public function test_number_is_uuid_with_suffix_for_zone_suborder(): void
  {
    $order = new Order();
    $order->uuid = '123-1';
    $order->parent_order_id = 150;
    $order->sub_number = 1;

    $this->assertSame('123-1', $order->number);
    $this->assertSame('123-1', $order->label);
  }

  public function test_number_is_uuid_for_no_zone_suborder_with_null_sub_number(): void
  {
    $order = new Order();
    $order->uuid = '123-2';
    $order->parent_order_id = 150;
    $order->sub_number = null;

    $this->assertSame('123-2', $order->number);
  }

  public function test_number_falls_back_to_legacy_label_without_uuid(): void
  {
    $order = new Order();
    $order->id = 150;
    $order->parent_order_id = 149;
    $order->sub_number = 2;

    $this->assertSame('149-2', $order->number);

    $regular = new Order();
    $regular->id = 150;

    $this->assertSame('150', $regular->number);
  }
}
