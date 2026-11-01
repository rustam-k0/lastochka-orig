<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductHasImgScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_has_img_scope_filters_by_image_presence_and_is_null_safe(): void
    {
        // Shop без HasFactory — фабрику вызываем напрямую
        $shop = ShopFactory::new()->create(['title' => 'Has Img Shop ' . uniqid()]);

        $withImg = Product::factory()->create(['shop_id' => $shop->id]);
        $withImg->saveImg(['path' => 'test/has-img-' . uniqid() . '.jpg']);

        $withoutImg = Product::factory()->create(['shop_id' => $shop->id]);

        $ids = [$withImg->id, $withoutImg->id];

        // has_img: true → только товары с фото
        $trueIds = Product::whereIn('id', $ids)->hasImg(true)->pluck('id')->all();
        $this->assertContains($withImg->id, $trueIds);
        $this->assertNotContains($withoutImg->id, $trueIds);

        // has_img: false → только товары без фото
        $falseIds = Product::whereIn('id', $ids)->hasImg(false)->pluck('id')->all();
        $this->assertContains($withoutImg->id, $falseIds);
        $this->assertNotContains($withImg->id, $falseIds);

        // has_img: null (явный) → не фильтрует и НЕ падает (регрессия HTTP 500
        // "Argument #2 must be of type bool, null given" от Lighthouse @scope)
        $nullIds = Product::whereIn('id', $ids)->hasImg(null)->pluck('id')->all();
        $this->assertContains($withImg->id, $nullIds);
        $this->assertContains($withoutImg->id, $nullIds);

        // без аргумента → эквивалентно null (новый default = не фильтровать)
        $defaultIds = Product::whereIn('id', $ids)->hasImg()->pluck('id')->all();
        $this->assertContains($withImg->id, $defaultIds);
        $this->assertContains($withoutImg->id, $defaultIds);
    }
}
