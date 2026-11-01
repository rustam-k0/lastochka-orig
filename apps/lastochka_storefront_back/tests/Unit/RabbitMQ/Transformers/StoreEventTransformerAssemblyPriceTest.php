<?php

declare(strict_types=1);

namespace Tests\Unit\RabbitMQ\Transformers;

use App\RabbitMQ\Transformers\StoreEventTransformer;
use Tests\TestCase;

final class StoreEventTransformerAssemblyPriceTest extends TestCase
{
    public function test_from_event_maps_assembly_price(): void
    {
        $attrs = (new StoreEventTransformer())->fromEvent([
            'shop_code' => 'shop_1',
            'source' => 'retailbridge',
            'title' => 'X',
            'assembly_price' => 300,
        ]);

        $this->assertSame(300, $attrs['assembly_price']);
    }

    public function test_from_event_assembly_price_null_when_absent(): void
    {
        $attrs = (new StoreEventTransformer())->fromEvent([
            'shop_code' => 'shop_1',
            'source' => 'retailbridge',
            'title' => 'X',
        ]);

        $this->assertNull($attrs['assembly_price']);
    }
}
