<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Banner;

use App\Enums\LinkType;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Store;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class BannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Конфиг мест показа баннеров (кастомные чекбоксы MerchantBanner),
     * как он приходит из admin_back через admin_settings-событие.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function seedPlacements(array $items): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['MerchantBanner' => $items]), 'type' => 'json'],
        );
    }

    /**
     * Шесть мест показа Electrohub, все включены.
     */
    private function seedElectrohubPlacements(): void
    {
        $this->seedPlacements([
            ['field' => 'main_after_catalog', 'label' => 'Главная: после блока Каталог', 'custom' => true, 'enabled' => true],
            ['field' => 'main_after_recommended', 'label' => 'Главная: после блока Рекомендуем (акция)', 'custom' => true, 'enabled' => true],
            ['field' => 'main_after_solutions', 'label' => 'Главная: после блока Комплексные решения', 'custom' => true, 'enabled' => true],
            ['field' => 'catalog_listing', 'label' => 'Каталог: баннер в листинге товаров', 'custom' => true, 'enabled' => true],
            ['field' => 'product_card', 'label' => 'Карточка товара: под брендом', 'custom' => true, 'enabled' => true],
            ['field' => 'solution_page', 'label' => 'Комплексные решения: перед блоком Другие стили', 'custom' => true, 'enabled' => true],
        ]);
    }

    // ==================== Get Store Banners ====================

    public function test_get_store_banners(): void
    {
        $store = Store::factory()->create();
        $banner1 = Banner::factory()->for($store)->create(['position' => 1, 'title' => 'Первый']);
        $banner2 = Banner::factory()->for($store)->create(['position' => 0, 'title' => 'Второй']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $banner2->id)
            ->assertJsonPath('data.0.title', 'Второй')
            ->assertJsonPath('data.1.id', $banner1->id)
            ->assertJsonPath('data.1.title', 'Первый');
    }

    public function test_get_store_banners_returns_correct_structure(): void
    {
        $store = Store::factory()->create();
        Banner::factory()->for($store)->create([
            'title' => 'Скидки на молочку',
            'image_path' => 'https://cdn.example.com/banner.jpg',
            'link_type' => LinkType::Category,
            'link_category_id' => null,
            'link_product_id' => null,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'title',
                        'image' => ['id', 'path'],
                        'linkType',
                        'linkProductId',
                        'linkCategoryId',
                        'displayCategoryId',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.title', 'Скидки на молочку')
            ->assertJsonPath('data.0.image.path', 'https://cdn.example.com/banner.jpg')
            ->assertJsonPath('data.0.linkType', 'category');
    }

    public function test_get_store_banners_excludes_inactive(): void
    {
        $store = Store::factory()->create();
        Banner::factory()->for($store)->create(['is_active' => true]);
        Banner::factory()->for($store)->inactive()->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_store_banners_excludes_other_store_banners(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();

        Banner::factory()->for($store)->create();
        Banner::factory()->for($otherStore)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_store_banners_returns_empty_for_no_banners(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_get_store_banners_returns_404_for_nonexistent_store(): void
    {
        $response = $this->getJson('/api/v1/stores/99999/banners');

        $response->assertNotFound();
    }

    // ==================== Category Banners ====================

    public function test_banners_endpoint_returns_only_main_page_banners_by_default(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $mainBanner = Banner::factory()->for($store)->create(['title' => 'Главный']);
        Banner::factory()->for($store)->create([
            'title' => 'Категорийный',
            'display_category_id' => $category->id,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mainBanner->id)
            ->assertJsonPath('data.0.displayCategoryId', null);
    }

    public function test_banners_endpoint_filters_by_category(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $otherCategory = Category::factory()->forStore($store)->create();

        Banner::factory()->for($store)->create(['title' => 'Главный']);
        $categoryBanner = Banner::factory()->for($store)->create([
            'title' => 'Свежие сладости каждый день!',
            'display_category_id' => $category->id,
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'Другая категория',
            'display_category_id' => $otherCategory->id,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?categoryId={$category->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $categoryBanner->id)
            ->assertJsonPath('data.0.title', 'Свежие сладости каждый день!')
            ->assertJsonPath('data.0.displayCategoryId', $category->id);
    }

    public function test_category_banners_respect_active_and_dates(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $visible = Banner::factory()->for($store)->create([
            'display_category_id' => $category->id,
            'position' => 0,
        ]);
        Banner::factory()->for($store)->inactive()->create([
            'display_category_id' => $category->id,
            'position' => 1,
        ]);
        Banner::factory()->for($store)->expired()->create([
            'display_category_id' => $category->id,
            'position' => 2,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?categoryId={$category->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_banners_endpoint_returns_empty_for_category_without_banners(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        Banner::factory()->for($store)->create(['title' => 'Главный']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?categoryId={$category->id}");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== Placement (места показа) ====================

    public function test_placement_returns_only_banners_with_flag_true(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();

        $withFlag = Banner::factory()->for($store)->create([
            'title' => 'После каталога',
            'position' => 0,
            'custom_checkboxes' => ['main_after_catalog' => true, 'product_card' => true],
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'Явно выключен',
            'position' => 1,
            'custom_checkboxes' => ['main_after_catalog' => false, 'main_after_recommended' => true],
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'Только другое место',
            'position' => 2,
            'custom_checkboxes' => ['product_card' => true],
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'Без тумблеров',
            'position' => 3,
            'custom_checkboxes' => [],
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'custom_checkboxes NULL',
            'position' => 4,
            'custom_checkboxes' => null,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?placement=main_after_catalog");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withFlag->id)
            ->assertJsonPath('data.0.customCheckboxes.main_after_catalog', true);
    }

    public function test_placement_keeps_position_order_and_active_dates_filters(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();

        $second = Banner::factory()->for($store)->create([
            'position' => 5,
            'custom_checkboxes' => ['product_card' => true],
        ]);
        $first = Banner::factory()->for($store)->create([
            'position' => 1,
            'custom_checkboxes' => ['product_card' => true],
        ]);
        Banner::factory()->for($store)->inactive()->create([
            'position' => 0,
            'custom_checkboxes' => ['product_card' => true],
        ]);
        Banner::factory()->for($store)->expired()->create([
            'position' => 0,
            'custom_checkboxes' => ['product_card' => true],
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?placement=product_card");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    public function test_placement_is_combined_with_category_id(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $mainWithFlag = Banner::factory()->for($store)->create([
            'title' => 'Главная + листинг',
            'custom_checkboxes' => ['catalog_listing' => true],
        ]);
        $categoryWithFlag = Banner::factory()->for($store)->create([
            'title' => 'Категория + листинг',
            'display_category_id' => $category->id,
            'custom_checkboxes' => ['catalog_listing' => true],
        ]);
        Banner::factory()->for($store)->create([
            'title' => 'Категория без флага',
            'display_category_id' => $category->id,
            'custom_checkboxes' => [],
        ]);

        // Без categoryId — только баннеры главной с флагом
        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=catalog_listing")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mainWithFlag->id);

        // С categoryId — только баннеры этой категории с флагом
        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=catalog_listing&categoryId={$category->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $categoryWithFlag->id);
    }

    public function test_placement_scoped_to_store(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();

        $own = Banner::factory()->for($store)->create(['custom_checkboxes' => ['solution_page' => true]]);
        Banner::factory()->for($otherStore)->create(['custom_checkboxes' => ['solution_page' => true]]);

        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=solution_page")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_without_placement_returns_all_main_banners_regardless_of_flags(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();

        Banner::factory()->for($store)->create(['position' => 0, 'custom_checkboxes' => ['product_card' => true]]);
        Banner::factory()->for($store)->create(['position' => 1, 'custom_checkboxes' => ['product_card' => false]]);
        Banner::factory()->for($store)->create(['position' => 2, 'custom_checkboxes' => []]);
        Banner::factory()->for($store)->create(['position' => 3, 'custom_checkboxes' => null]);

        $this->getJson("/api/v1/stores/{$store->id}/banners")
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_unknown_placement_returns_422_with_allowed_values(): void
    {
        $this->seedPlacements([
            ['field' => 'main_after_catalog', 'label' => 'Главная: после блока Каталог', 'custom' => true, 'enabled' => true],
            ['field' => 'product_card', 'label' => 'Карточка товара: под брендом', 'custom' => true, 'enabled' => true],
        ]);
        $store = Store::factory()->create();
        Banner::factory()->for($store)->create(['custom_checkboxes' => ['main_after_catalog' => true]]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners?placement=home_slider");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['placement'])
            ->assertJsonPath('errors.placement.0', 'Допустимые значения: main_after_catalog, product_card');
    }

    public function test_disabled_placement_returns_422(): void
    {
        $this->seedPlacements([
            ['field' => 'main_after_catalog', 'label' => 'Главная: после блока Каталог', 'custom' => true, 'enabled' => true],
            ['field' => 'product_card', 'label' => 'Карточка товара: под брендом', 'custom' => true, 'enabled' => false],
        ]);
        $store = Store::factory()->create();
        Banner::factory()->for($store)->create(['custom_checkboxes' => ['product_card' => true]]);

        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=product_card")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['placement']);
    }

    public function test_placement_returns_422_when_no_placements_configured(): void
    {
        Cache::flush();
        $store = Store::factory()->create();
        Banner::factory()->for($store)->create(['custom_checkboxes' => ['product_card' => true]]);

        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=product_card")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['placement'])
            ->assertJsonPath('errors.placement.0', 'Места показа баннеров не настроены');
    }

    public function test_empty_placement_is_ignored(): void
    {
        $this->seedElectrohubPlacements();
        $store = Store::factory()->create();
        Banner::factory()->for($store)->count(2)->create(['custom_checkboxes' => []]);

        $this->getJson("/api/v1/stores/{$store->id}/banners?placement=")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ==================== Banners Filtered By Dates ====================

    public function test_banners_filtered_by_dates(): void
    {
        $store = Store::factory()->create();

        // Баннер без дат — всегда показывается
        $alwaysVisible = Banner::factory()->for($store)->create([
            'title' => 'Всегда',
            'starts_at' => null,
            'ends_at' => null,
            'position' => 0,
        ]);

        // Баннер с текущей датой в диапазоне
        $currentBanner = Banner::factory()->for($store)->create([
            'title' => 'Текущий',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'position' => 1,
        ]);

        // Истёкший баннер
        Banner::factory()->for($store)->expired()->create([
            'title' => 'Истёкший',
            'position' => 2,
        ]);

        // Будущий баннер
        Banner::factory()->for($store)->future()->create([
            'title' => 'Будущий',
            'position' => 3,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $alwaysVisible->id)
            ->assertJsonPath('data.1.id', $currentBanner->id);
    }

    public function test_banners_with_only_starts_at(): void
    {
        $store = Store::factory()->create();

        // starts_at в прошлом, ends_at null — показывается
        $visible = Banner::factory()->for($store)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'position' => 0,
        ]);

        // starts_at в будущем, ends_at null — не показывается
        Banner::factory()->for($store)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => null,
            'position' => 1,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_banners_with_only_ends_at(): void
    {
        $store = Store::factory()->create();

        // starts_at null, ends_at в будущем — показывается
        $visible = Banner::factory()->for($store)->create([
            'starts_at' => null,
            'ends_at' => now()->addDay(),
            'position' => 0,
        ]);

        // starts_at null, ends_at в прошлом — не показывается
        Banner::factory()->for($store)->create([
            'starts_at' => null,
            'ends_at' => now()->subDay(),
            'position' => 1,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }
}
