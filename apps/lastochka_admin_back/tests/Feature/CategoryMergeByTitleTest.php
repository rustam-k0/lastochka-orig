<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Transformers\CategoryEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Проверяет merge-by-title fallback в CategoryEventTransformer::findExistingEntity.
 *
 * Нужно для initial resync категорий из 1С: в event_sync_metadata пусто,
 * но админ уже создал одноимённые категории — пакет не должен задваивать.
 */
class CategoryMergeByTitleTest extends TestCase
{
    use DatabaseTransactions;

    private CategoryEventTransformer $transformer;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new CategoryEventTransformer();
        $this->shop = Shop::firstOrFail();
    }

    /** @test */
    public function finds_existing_category_with_same_title_in_shop(): void
    {
        $local = Category::create(['title' => 'Кулинария', 'link' => 'kulinariya-local-' . uniqid()]);
        ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $local->id]);

        $found = $this->transformer->findExistingEntity(
            eventData: ['title' => 'Кулинария', 'shop_code' => $this->shop->code],
            transformedData: [],
        );

        $this->assertNotNull($found);
        $this->assertSame($local->id, $found->id);
    }

    /** @test */
    public function match_is_case_insensitive_and_trims_whitespace(): void
    {
        $local = Category::create(['title' => 'Кулинария', 'link' => 'kulinariya-ci-' . uniqid()]);
        ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $local->id]);

        $variants = ['  кулинария', 'КУЛИНАРИЯ', 'Кулинария '];
        foreach ($variants as $title) {
            $found = $this->transformer->findExistingEntity(
                eventData: ['title' => $title, 'shop_code' => $this->shop->code],
                transformedData: [],
            );
            $this->assertNotNull($found, "Вариант '{$title}' должен матчиться");
            $this->assertSame($local->id, $found->id);
        }
    }

    /** @test */
    public function does_not_match_category_from_different_shop(): void
    {
        $otherShop = Shop::create(['title' => 'Other ' . uniqid(), 'code' => 'other_' . uniqid(), 'phone' => '+79990000000']);
        $local = Category::create(['title' => 'Уникальное название', 'link' => 'unique-' . uniqid()]);
        ShopCategory::firstOrCreate(['shop_id' => $otherShop->id, 'category_id' => $local->id]);

        $found = $this->transformer->findExistingEntity(
            eventData: ['title' => 'Уникальное название', 'shop_code' => $this->shop->code],
            transformedData: [],
        );

        $this->assertNull($found, 'Категория из другого магазина не должна матчиться');
    }

    /** @test */
    public function returns_null_when_no_match(): void
    {
        $found = $this->transformer->findExistingEntity(
            eventData: ['title' => 'Несуществующая категория ' . uniqid(), 'shop_code' => $this->shop->code],
            transformedData: [],
        );

        $this->assertNull($found);
    }

    /** @test */
    public function returns_null_when_title_is_empty(): void
    {
        $found = $this->transformer->findExistingEntity(
            eventData: ['title' => '', 'shop_code' => $this->shop->code],
            transformedData: [],
        );

        $this->assertNull($found);
    }

    /** @test */
    public function returns_null_when_shop_code_is_missing(): void
    {
        $local = Category::create(['title' => 'Без shop_code ' . uniqid(), 'link' => 'nsc-' . uniqid()]);
        ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $local->id]);

        $found = $this->transformer->findExistingEntity(
            eventData: ['title' => $local->title],
            transformedData: [],
        );

        $this->assertNull($found);
    }
}
