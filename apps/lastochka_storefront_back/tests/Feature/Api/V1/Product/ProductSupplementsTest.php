<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductSupplementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_supplements_returns_supplements_in_api(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 170,
            'quantity' => 10,
        ]);

        // Создаём группу добавок type=one
        $supplement1 = Supplement::create([
            'title' => 'Соус на выбор',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => true,
        ]);

        SupplementValue::create(['title' => 'Сырный', 'price' => 0, 'supplement_id' => $supplement1->id]);
        SupplementValue::create(['title' => 'Кетчуп', 'price' => 0, 'supplement_id' => $supplement1->id]);

        // Создаём группу добавок type=many
        $supplement2 = Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        SupplementValue::create(['title' => 'Аджика', 'price' => 50, 'supplement_id' => $supplement2->id]);
        SupplementValue::create(['title' => 'Сметана с чесноком', 'price' => 40, 'supplement_id' => $supplement2->id]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.supplements')
            ->assertJsonPath('data.supplements.0.title', 'Соус на выбор')
            ->assertJsonPath('data.supplements.0.type', 'one')
            ->assertJsonPath('data.supplements.0.isRequired', true)
            ->assertJsonCount(2, 'data.supplements.0.values')
            ->assertJsonPath('data.supplements.1.title', 'Допы')
            ->assertJsonPath('data.supplements.1.type', 'many')
            ->assertJsonPath('data.supplements.1.isRequired', false)
            ->assertJsonPath('data.supplements.1.values.0.price', 50);
    }

    public function test_product_without_supplements_returns_empty_array(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.supplements', []);
    }

    public function test_supplements_not_loaded_in_product_list(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        Supplement::create([
            'title' => 'Тест',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => false,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products");

        $response->assertOk();
        // В списках supplements не загружается — должен быть пустой массив (default)
        $response->assertJsonPath('data.0.supplements', []);
    }
}
