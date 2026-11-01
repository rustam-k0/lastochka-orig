<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Product\ProductUpdate;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ProductUpdate::syncCategories — правило «только листовые категории» применяется
 * лишь к НОВО-добавляемым категориям. Ранее привязанные нелистовые (узел получил
 * дочерние уже после привязки) допускаются — иначе редактирование таких товаров
 * блокируется полностью (даже правка остатков), т.к. форма всегда шлёт текущие
 * category_ids. Ошибка отдаётся как validation (ключ category_ids) с понятным
 * текстом, а не сырым GraphQL\Error.
 */
class ProductUpdateLeafCategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected Shop $shop;
    protected Category $nonLeaf;   // имеет ребёнка -> нелистовая
    protected Category $leaf;      // без детей -> листовая
    protected Category $otherNonLeaf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Leaf Cat Shop ' . uniqid(),
            'code' => 'leaf-cat-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        // nonLeaf
        //   └ child (делает nonLeaf нелистовой)
        $this->nonLeaf = Category::create(['title' => 'Варенье ' . uniqid(), 'parent_id' => null]);
        Category::create(['title' => 'Подкатегория ' . uniqid(), 'parent_id' => $this->nonLeaf->id]);

        $this->leaf = Category::create(['title' => 'Лист ' . uniqid(), 'parent_id' => null]);

        $this->otherNonLeaf = Category::create(['title' => 'Соусы ' . uniqid(), 'parent_id' => null]);
        Category::create(['title' => 'Подкатегория Б ' . uniqid(), 'parent_id' => $this->otherNonLeaf->id]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'title' => 'Тестовый товар ' . uniqid(),
            'shop_id' => $this->shop->id,
        ]);
    }

    /** Вызов приватного syncCategories напрямую — он держит всю логику правила. */
    private function syncCategories(Product $product, array $categoryIds): void
    {
        $method = new \ReflectionMethod(ProductUpdate::class, 'syncCategories');
        $method->setAccessible(true);
        $method->invoke(new ProductUpdate(), $product, $categoryIds);
    }

    /** @test */
    public function preexisting_non_leaf_category_is_grandfathered_on_save()
    {
        $product = $this->makeProduct();
        // Легаси-привязка к нелистовой категории (была листом на момент привязки)
        $product->categories()->sync([$this->nonLeaf->id => ['position' => 0]]);

        // Сохранение с тем же набором не должно падать
        $this->syncCategories($product, [$this->nonLeaf->id]);

        $this->assertEqualsCanonicalizing(
            [$this->nonLeaf->id],
            $product->categories()->pluck('categories.id')->map(fn ($v) => (int) $v)->all()
        );
    }

    /** @test */
    public function newly_added_non_leaf_category_is_rejected_with_validation_error()
    {
        $product = $this->makeProduct();
        $product->categories()->sync([$this->nonLeaf->id => ['position' => 0]]);

        try {
            // nonLeaf уже привязан (ok), но otherNonLeaf добавляется впервые -> reject
            $this->syncCategories($product, [$this->nonLeaf->id, $this->otherNonLeaf->id]);
            $this->fail('Ожидалась ValidationException на ново-добавляемую нелистовую категорию');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_ids', $e->errors());
            $this->assertStringContainsString(
                $this->otherNonLeaf->title,
                $e->errors()['category_ids'][0],
                'Сообщение должно называть проблемную категорию'
            );
            // Привязки не изменились
            $this->assertEqualsCanonicalizing(
                [$this->nonLeaf->id],
                $product->categories()->pluck('categories.id')->map(fn ($v) => (int) $v)->all()
            );
        }
    }

    /** @test */
    public function newly_added_leaf_category_is_allowed()
    {
        $product = $this->makeProduct();
        $product->categories()->sync([$this->nonLeaf->id => ['position' => 0]]);

        $this->syncCategories($product, [$this->nonLeaf->id, $this->leaf->id]);

        $this->assertEqualsCanonicalizing(
            [$this->nonLeaf->id, $this->leaf->id],
            $product->categories()->pluck('categories.id')->map(fn ($v) => (int) $v)->all()
        );
    }

    /** @test */
    public function non_leaf_category_on_product_without_attachments_is_rejected()
    {
        // Нет ранее привязанных категорий -> nonLeaf считается ново-добавляемой
        $product = $this->makeProduct();

        $this->expectException(ValidationException::class);
        $this->syncCategories($product, [$this->nonLeaf->id]);
    }
}
