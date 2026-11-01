<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesProductReadyTimeModule;
use Tests\TestCase;

final class ProductReadyTimeResourceTest extends TestCase
{
    use RefreshDatabase;
    use TogglesProductReadyTimeModule;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->invalidateProductReadyTimeCache();
        parent::tearDown();
    }

    private function makeProduct(array $ready): Product
    {
        return Product::factory()->create(array_merge(
            ['store_id' => Store::factory()->create()->id],
            $ready,
        ));
    }

    public function test_fixed_future_time_gives_available_from_today(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 09:00:00', config('app.timezone')));

        $product = $this->makeProduct(['ready_fixed_time' => '12:00:00']);

        $data = ProductResource::make($product)->resolve();

        $this->assertSame(
            Carbon::parse('2026-07-02 12:00:00', config('app.timezone'))->toIso8601String(),
            $data['availableFrom'],
        );
    }

    public function test_fixed_past_time_gives_null(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 13:00:00', config('app.timezone')));

        $product = $this->makeProduct(['ready_fixed_time' => '12:00:00']);

        $this->assertNull(ProductResource::make($product)->resolve()['availableFrom']);
    }

    public function test_field_absent_when_module_disabled(): void
    {
        $this->setProductReadyTimeModule(false);

        $product = $this->makeProduct(['ready_fixed_time' => '12:00:00']);

        $this->assertArrayNotHasKey('availableFrom', ProductResource::make($product)->resolve());
    }

    public function test_product_without_readiness_gives_null(): void
    {
        $this->setProductReadyTimeModule(true);

        $product = $this->makeProduct(['ready_fixed_time' => null]);

        $this->assertNull(ProductResource::make($product)->resolve()['availableFrom']);
    }
}
