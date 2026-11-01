<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductPickupOnlyResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(bool $pickupOnly): Product
    {
        return Product::factory()->create([
            'store_id' => Store::factory()->create()->id,
            'pickup_only' => $pickupOnly,
        ]);
    }

    public function test_pickup_only_true_is_exposed_in_product_resource(): void
    {
        $product = $this->makeProduct(true);

        $data = ProductResource::make($product)->resolve();

        $this->assertTrue($data['pickupOnly']);
    }

    public function test_pickup_only_false_is_exposed_in_product_resource(): void
    {
        $product = $this->makeProduct(false);

        $data = ProductResource::make($product)->resolve();

        $this->assertFalse($data['pickupOnly']);
    }
}
