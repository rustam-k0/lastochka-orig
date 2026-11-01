<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemainExistsIgnoreTest extends TestCase
{
    use DatabaseTransactions;

    private function getOrCreateShop(): Shop
    {
        $existing = Shop::find(990407);
        if ($existing) {
            return $existing;
        }
        return Shop::forceCreate(['id' => 990407, 'title' => 'P', 'ignore_stock' => false]);
    }

    public function test_accessor_fixed_mode(): void
    {
        $shop = $this->getOrCreateShop();
        $p = Product::forceCreate(['title' => 'X', 'shop_id' => $shop->id, 'effective_stock_mode' => 'fixed', 'unit_type_id' => 2, 'status_id' => 3]);
        $this->assertTrue($p->effectiveStockIgnored());
    }

    public function test_accessor_ignore_mode(): void
    {
        $shop = $this->getOrCreateShop();
        $p = Product::forceCreate(['title' => 'Y', 'shop_id' => $shop->id, 'effective_stock_mode' => 'ignore', 'unit_type_id' => 2, 'status_id' => 3]);
        $this->assertTrue($p->effectiveStockIgnored());
    }

    public function test_accessor_normal_mode(): void
    {
        $shop = $this->getOrCreateShop();
        $p = Product::forceCreate(['title' => 'Z', 'shop_id' => $shop->id, 'effective_stock_mode' => 'normal', 'unit_type_id' => 2, 'status_id' => 3]);
        $this->assertFalse($p->effectiveStockIgnored());
    }
}
