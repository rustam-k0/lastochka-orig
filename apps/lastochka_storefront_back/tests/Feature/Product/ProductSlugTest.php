<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Product\ProductSlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ЧПУ-слаг товара: генерируется из title при создании (транслит ru),
 * уникализируется суффиксом, не меняется при переименовании.
 * Товары из RabbitMQ создаются через withoutEvents() — слаг им ставит
 * ProductEventTransformer::ensureSlug().
 */
final class ProductSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_generated_from_cyrillic_title_on_create(): void
    {
        $product = Product::factory()->create(['title' => 'Шашлык из куриного филе 1,1 кг']);

        $this->assertSame('shashlyk-iz-kurinogo-file-11-kg', $product->slug);
    }

    public function test_slug_collision_gets_numeric_suffix(): void
    {
        $first = Product::factory()->create(['title' => 'Кетчуп Махеев']);
        $second = Product::factory()->create(['title' => 'Кетчуп Махеев']);
        $third = Product::factory()->create(['title' => 'Кетчуп Махеев']);

        $this->assertSame('ketchup-maheev', $first->slug);
        $this->assertSame('ketchup-maheev-2', $second->slug);
        $this->assertSame('ketchup-maheev-3', $third->slug);
    }

    public function test_slug_not_regenerated_on_title_change(): void
    {
        $product = Product::factory()->create(['title' => 'Старое название']);
        $slug = $product->slug;

        $product->update(['title' => 'Совсем новое название']);

        $this->assertSame($slug, $product->fresh()->slug);
    }

    public function test_empty_title_gives_null_slug(): void
    {
        $this->assertNull(app(ProductSlugGenerator::class)->generate(''));
        $this->assertNull(app(ProductSlugGenerator::class)->generate(null));
    }

    public function test_transformer_ensure_slug_fills_products_created_without_events(): void
    {
        // Consumer сохраняет через withoutEvents() → creating-хук модели молчит
        $product = Product::withoutEvents(
            fn () => Product::factory()->create(['title' => 'Пиво Жигулёвское 0,5 л'])
        );
        $this->assertNull($product->slug);

        $transformer = app(ProductEventTransformer::class);
        $method = new \ReflectionMethod($transformer, 'ensureSlug');
        $method->invoke($transformer, $product);

        $this->assertSame('pivo-zhigulyovskoe-05-l', $product->fresh()->slug);
    }

    public function test_slug_returned_in_product_show_and_price_is_float(): void
    {
        $product = Product::factory()->create(['title' => 'Сыр Российский', 'price' => 539.99]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.slug', 'syr-rossiyskiy');

        $this->assertIsFloat($response->json('data.price'));
        $this->assertSame(539.99, $response->json('data.price'));
    }

    public function test_slug_returned_in_product_groups_home(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['title' => 'Колбаса Докторская']);

        $group = ProductGroup::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'show_on_home' => true,
        ]);
        $group->products()->attach($product->id, ['position' => 0]);

        $response = $this->getJson('/api/v1/product-groups?placement=home&withProducts=true');

        $response->assertOk()
            ->assertJsonPath('data.0.products.0.slug', 'kolbasa-doktorskaya');
    }
}
