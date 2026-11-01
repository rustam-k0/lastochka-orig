<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderAssemblyCostColumnTest extends TestCase
{
    use RefreshDatabase; // storefront phpunit.xml = sqlite :memory:, безопасно

    public function test_orders_has_assembly_cost(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'assembly_cost'));
    }

    public function test_assembly_cost_is_fillable_and_cast_to_integer(): void
    {
        $order = new Order(['assembly_cost' => '250']);

        $this->assertSame(250, $order->assembly_cost);
    }
}
