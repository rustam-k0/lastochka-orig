<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreAssemblyPriceTest extends TestCase
{
    use RefreshDatabase; // storefront phpunit.xml = sqlite :memory:, безопасно

    public function test_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('stores', 'assembly_price'));
    }

    public function test_cast_to_integer(): void
    {
        $store = new Store(['assembly_price' => '250']);
        $this->assertSame(250, $store->assembly_price);
    }
}
