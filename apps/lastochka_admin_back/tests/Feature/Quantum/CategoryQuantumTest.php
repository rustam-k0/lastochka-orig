<?php

namespace Tests\Feature\Quantum;

use App\GraphQL\Mutations\Category\SetCategoryQuantum;
use App\GraphQL\Mutations\Product\ProductUpdate;
use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\Services\Quantum\CategoryQuantumService;
use App\Services\Quantum\UnitFamily;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Адаптивный квант продажи на ВИТРИННЫХ категориях (LASTOCHKI-JAMI-11200):
 * единица + шаг (масса/объём/штуки), резолв по совпадению семейства, пересчёт
 * цены за квант от нормализованной цены 1С (price_per_small_unit).
 */
class CategoryQuantumTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;
    private CategoryQuantumService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->shop = Shop::query()->find(990001) ?? Shop::forceCreate([
            'id' => 990001, 'title' => 'Квант-тест', 'code' => 'quantum_test_shop',
        ]);
        $this->service = app(CategoryQuantumService::class);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function leafCategory(?int $step = null, int $unit = Type::INFO_WEIGHT_GRAM): Category
    {
        return Category::create([
            'title' => 'Cat ' . uniqid(),
            'quantum_step' => $step,
            'quantum_unit_type_id' => $step !== null ? $unit : null,
            'is_active' => true,
        ]);
    }

    /**
     * Товар с базовой единицей $baseUnit и сырой ценой $rawPrice за базовую единицу.
     * price_per_small_unit нормализуется (кг 411 → 0.411 ₽/г; шт 50 → 50 ₽/шт).
     */
    private function product(array $categories, float $rawPrice = 411.00, int $baseUnit = Type::INFO_WEIGHT_KILOGRAM, ?int $manualStep = null, ?int $manualUnit = null): Product
    {
        $product = Product::create([
            'title' => 'P ' . uniqid(),
            'articul' => 'p-' . uniqid(),
            'unit_type_id' => $baseUnit === Type::INFO_WEIGHT_PIECE ? Type::UNIT_PIECE : Type::UNIT_FOOD,
            'info_weight_type_id' => $baseUnit,
            'base_unit_type_id' => $baseUnit,
            'shop_id' => $this->shop->id,
            'manual_quantum_step' => $manualStep,
            'manual_quantum_unit_type_id' => $manualUnit,
        ]);
        $product->categories()->sync(collect($categories)->pluck('id')->all());

        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $rawPrice,
            'price_per_small_unit' => round($rawPrice / UnitFamily::sizeInSmallest($baseUnit), 4),
            'step' => 1,
        ]);

        return $product->fresh();
    }

    private function defaultPrice(Product $p): Price
    {
        return Price::where('product_id', $p->id)->where('shop_id', $this->shop->id)
            ->where('type_id', Type::PRICE_DEFAULT)->first();
    }

    // --- resolveQuantum / семейства ---

    public function test_resolve_min_step_within_mass_family(): void
    {
        $product = $this->product([$this->leafCategory(250, 2), $this->leafCategory(200, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM);
        $resolved = $this->service->resolveQuantum($product);
        $this->assertSame(200, $resolved['step']);
        $this->assertSame(Type::INFO_WEIGHT_GRAM, $resolved['unit']);
    }

    public function test_family_mismatch_skipped(): void
    {
        // штучный товар в категории с граммовым квантом → квант не применяется
        $product = $this->product([$this->leafCategory(250, 2)], 50.00, Type::INFO_WEIGHT_PIECE);
        $this->assertNull($this->service->resolveQuantum($product));
    }

    public function test_resolve_null_when_no_category_quantum(): void
    {
        $product = $this->product([$this->leafCategory(null)], 411.00, Type::INFO_WEIGHT_KILOGRAM);
        $this->assertNull($this->service->resolveQuantum($product));
    }

    public function test_resolve_falls_back_to_manual_quantum(): void
    {
        $product = $this->product([$this->leafCategory(null)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $resolved = $this->service->resolveQuantum($product);
        $this->assertSame(200, $resolved['step']);
        $this->assertSame(Type::INFO_WEIGHT_GRAM, $resolved['unit']);
    }

    public function test_manual_quantum_overrides_category(): void
    {
        // товар в категории с квантом 250 г + ручной квант 200 г → берётся РУЧНОЙ (исключение)
        $product = $this->product([$this->leafCategory(250, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $resolved = $this->service->resolveQuantum($product);
        $this->assertSame(200, $resolved['step']);
        $this->assertSame(Type::INFO_WEIGHT_GRAM, $resolved['unit']);
    }

    public function test_resolve_category_quantum_ignores_manual(): void
    {
        $withManual = $this->product([$this->leafCategory(null)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $this->assertNull($this->service->resolveCategoryQuantum($withManual));

        $withCategory = $this->product([$this->leafCategory(250, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $this->assertSame(250, $this->service->resolveCategoryQuantum($withCategory)['step']);
    }

    // --- applyToProduct по семействам ---

    public function test_mass_quantum_recalc_grams(): void
    {
        // кг 411 ₽ → 0.411 ₽/г; квант 250 г → 102.75 ₽
        $product = $this->product([$this->leafCategory(250, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM);
        $this->assertTrue($this->service->applyToProduct($product, $this->shop->id, 411.00, dispatchEvent: false));

        $price = $this->defaultPrice($product);
        $this->assertSame(102.75, (float) $price->value);
        $this->assertSame(250, (int) $price->step);
        // price_per_kg (shared с purchase_back/delivery_back) сохраняется = цена за кг.
        $this->assertSame(411.00, (float) $price->price_per_kg);

        $product->refresh();
        $this->assertSame(250, (int) $product->info_weight);
        $this->assertSame(Type::INFO_WEIGHT_GRAM, (int) $product->info_weight_type_id);
        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->base_unit_type_id); // не перетёрт
    }

    public function test_piece_quantum_recalc(): void
    {
        // штука 50 ₽; квант 10 шт → 500 ₽
        $product = $this->product([$this->leafCategory(10, 1)], 50.00, Type::INFO_WEIGHT_PIECE);
        $this->assertTrue($this->service->applyToProduct($product, $this->shop->id, 50.00, dispatchEvent: false));

        $price = $this->defaultPrice($product);
        $this->assertSame(500.00, (float) $price->value);
        $this->assertSame(10, (int) $price->step);

        $product->refresh();
        $this->assertSame(10, (int) $product->info_weight);
        $this->assertSame(Type::INFO_WEIGHT_PIECE, (int) $product->info_weight_type_id);
    }

    public function test_volume_quantum_deferred_not_applied(): void
    {
        // Объём (мл) пока не в allowedQuantumUnits → квант не применяется,
        // товар продаётся за базовую единицу (литр). Покрывает «объём позже».
        $product = $this->product([$this->leafCategory(500, 5)], 80.00, Type::INFO_WEIGHT_LITER);
        $this->assertNull($this->service->resolveQuantum($product));

        $this->service->applyToProduct($product, $this->shop->id, 80.00, dispatchEvent: false);
        $this->assertSame(80.00, (float) $this->defaultPrice($product)->value); // за литр
        $this->assertSame(1, (int) $this->defaultPrice($product)->step);
    }

    public function test_apply_recalculates_from_manual_quantum(): void
    {
        // кг 411 ₽, ручной квант 200 г → 82.20 ₽
        $product = $this->product([$this->leafCategory(null)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $this->assertTrue($this->service->applyToProduct($product, $this->shop->id, 411.00, dispatchEvent: false));
        $this->assertSame(82.20, (float) $this->defaultPrice($product)->value);
        $this->assertSame(200, (int) $this->defaultPrice($product)->step);
    }

    public function test_apply_uses_manual_quantum_over_category(): void
    {
        // кг 411 ₽; категория 250 г, но ручной квант 200 г → 0.411 × 200 = 82.20 ₽
        $product = $this->product([$this->leafCategory(250, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 200, manualUnit: Type::INFO_WEIGHT_GRAM);
        $this->assertTrue($this->service->applyToProduct($product, $this->shop->id, 411.00, dispatchEvent: false));
        $this->assertSame(82.20, (float) $this->defaultPrice($product)->value);
        $this->assertSame(200, (int) $this->defaultPrice($product)->step);
    }

    public function test_apply_returns_to_base_unit_when_no_quantum(): void
    {
        // кг 411 ₽, кванта нет → цена за кг (411), step=1, info_weight=null, тип=кг
        $product = $this->product([$this->leafCategory(null)], 411.00, Type::INFO_WEIGHT_KILOGRAM);
        $this->service->applyToProduct($product, $this->shop->id, 411.00, dispatchEvent: false);

        $price = $this->defaultPrice($product);
        $this->assertSame(411.00, (float) $price->value);
        $this->assertSame(1, (int) $price->step);
        $product->refresh();
        $this->assertNull($product->info_weight);
        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->info_weight_type_id);
    }

    public function test_apply_recalc_from_stored_when_price_arg_null(): void
    {
        // price_per_small_unit=0.585 сохранён (кг 585); квант 500 г → 292.50
        $product = $this->product([$this->leafCategory(500, 2)], 585.00, Type::INFO_WEIGHT_KILOGRAM);
        $this->service->applyToProduct($product, $this->shop->id, null, dispatchEvent: false);
        $this->assertSame(292.50, (float) $this->defaultPrice($product)->value);
    }

    public function test_recalc_category_recalculates_matching_family(): void
    {
        $cat = $this->leafCategory(200, 2);
        $this->product([$cat], 680.00, Type::INFO_WEIGHT_KILOGRAM); // → 136.00
        $this->product([$cat], 280.00, Type::INFO_WEIGHT_KILOGRAM); // → 56.00
        $this->product([$cat], 50.00, Type::INFO_WEIGHT_PIECE);     // штучный — пропуск

        $count = $this->service->recalcCategory($cat->fresh());

        $this->assertSame(2, $count);
        $values = Price::whereIn('product_id', $cat->products()->pluck('products.id'))
            ->where('type_id', Type::PRICE_DEFAULT)->pluck('value')->map(fn ($v) => (float) $v)->all();
        $this->assertEqualsCanonicalizing([136.00, 56.00, 50.00], $values);
    }

    // --- мутация SetCategoryQuantum ---

    public function test_mutation_sets_mass_quantum_and_recalcs(): void
    {
        $cat = $this->leafCategory(null);
        $product = $this->product([$cat], 411.00, Type::INFO_WEIGHT_KILOGRAM);

        $result = app(SetCategoryQuantum::class)(null, [
            'category_id' => $cat->id,
            'quantum_step' => 250,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);

        $this->assertSame(250, (int) $result->quantum_step);
        $this->assertSame(Type::INFO_WEIGHT_GRAM, (int) $result->quantum_unit_type_id);
        $this->assertSame(102.75, (float) $this->defaultPrice($product)->value);
    }

    public function test_mutation_sets_piece_quantum(): void
    {
        $cat = $this->leafCategory(null);
        $product = $this->product([$cat], 50.00, Type::INFO_WEIGHT_PIECE);

        $result = app(SetCategoryQuantum::class)(null, [
            'category_id' => $cat->id,
            'quantum_step' => 10,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->assertSame(10, (int) $result->quantum_step);
        $this->assertSame(500.00, (float) $this->defaultPrice($product)->value);
    }

    public function test_mutation_rejects_invalid_unit(): void
    {
        $cat = $this->leafCategory(null);
        $this->expectException(ValidationException::class);
        app(SetCategoryQuantum::class)(null, [
            'category_id' => $cat->id,
            'quantum_step' => 2,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM, // не наименьшая — запрещено
        ]);
    }

    public function test_mutation_rejects_non_leaf_category(): void
    {
        $parent = $this->leafCategory(null);
        Category::create(['title' => 'child', 'parent_id' => $parent->id, 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(SetCategoryQuantum::class)(null, [
            'category_id' => $parent->id, 'quantum_step' => 250, 'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
    }

    public function test_mutation_rejects_out_of_range(): void
    {
        $cat = $this->leafCategory(null);
        $this->expectException(ValidationException::class);
        app(SetCategoryQuantum::class)(null, [
            'category_id' => $cat->id, 'quantum_step' => 200000, 'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
    }

    public function test_price_updated_event_recalcs_to_quantum(): void
    {
        $product = $this->product([$this->leafCategory(250, 2)], 411.00, Type::INFO_WEIGHT_KILOGRAM);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-1',
            'shop_code' => $this->shop->code,
            'price' => 411.00,
            'unit_type' => 'kg',
        ]);

        $this->assertSame(102.75, (float) $this->defaultPrice($product)->value);
        $this->assertSame(250, (int) $this->defaultPrice($product)->step);
    }

    // --- Product::isWeight() / scopeWeight(): единый гейт «весовой» на info_weight_type_id ---

    private function productWithInfoWeightType(?int $infoWeightTypeId): Product
    {
        return Product::create([
            'title' => 'P ' . uniqid(),
            'articul' => 'iw-' . uniqid(),
            'unit_type_id' => Type::UNIT_PIECE,
            'info_weight_type_id' => $infoWeightTypeId,
            'shop_id' => $this->shop->id,
        ])->fresh();
    }

    public function test_is_weight_true_for_gram_and_kilogram(): void
    {
        $this->assertTrue($this->productWithInfoWeightType(Type::INFO_WEIGHT_GRAM)->isWeight());
        $this->assertTrue($this->productWithInfoWeightType(Type::INFO_WEIGHT_KILOGRAM)->isWeight());
    }

    public function test_is_weight_false_for_piece(): void
    {
        $this->assertFalse($this->productWithInfoWeightType(Type::INFO_WEIGHT_PIECE)->isWeight());
    }

    public function test_is_weight_false_for_null_info_weight_type(): void
    {
        $this->assertFalse($this->productWithInfoWeightType(null)->isWeight());
    }

    public function test_scope_weight_matches_is_weight_and_excludes_null(): void
    {
        $gram = $this->productWithInfoWeightType(Type::INFO_WEIGHT_GRAM);
        $kg = $this->productWithInfoWeightType(Type::INFO_WEIGHT_KILOGRAM);
        $piece = $this->productWithInfoWeightType(Type::INFO_WEIGHT_PIECE);
        $null = $this->productWithInfoWeightType(null);

        $ids = Product::query()->weight()
            ->whereIn('id', [$gram->id, $kg->id, $piece->id, $null->id])
            ->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$gram->id, $kg->id], $ids);
        $this->assertSame($gram->isWeight(), in_array($gram->id, $ids, true));
        $this->assertSame($null->isWeight(), in_array($null->id, $ids, true));
        $this->assertSame($piece->isWeight(), in_array($piece->id, $ids, true));
    }

    public function test_recalc_category_preserves_manual_exception(): void
    {
        // категория 250 г; товар А — исключение 100 г, товар Б — без исключения
        $cat = $this->leafCategory(250, 2);
        $a = $this->product([$cat], 411.00, Type::INFO_WEIGHT_KILOGRAM, manualStep: 100, manualUnit: Type::INFO_WEIGHT_GRAM);
        $b = $this->product([$cat], 411.00, Type::INFO_WEIGHT_KILOGRAM);

        $this->service->recalcCategory($cat->fresh());

        // А пересчитан по исключению (0.411 × 100 = 41.10), Б — по категории (× 250 = 102.75)
        $this->assertSame(41.10, (float) $this->defaultPrice($a)->value);
        $this->assertSame(100, (int) $this->defaultPrice($a)->step);
        $this->assertSame(102.75, (float) $this->defaultPrice($b)->value);
        $this->assertSame(250, (int) $this->defaultPrice($b)->step);
    }

    // --- ProductUpdate: фиксация base_unit_type_id у ручных товаров ---

    private function actingAsGlobalAdmin(): void
    {
        AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');
        $admin = User::create([
            'name' => 'Admin',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));
        $this->assertTrue($admin->isGlobalScope());
        $this->actingAs($admin);
    }

    public function test_manual_product_fixes_base_unit_from_info_weight(): void
    {
        $this->actingAsGlobalAdmin();

        // ручной товар: единица кг, base_unit_type_id ещё не задан
        $product = Product::create([
            'title' => 'manual ' . uniqid(),
            'articul' => 'm-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
        ]);
        $this->assertNull($product->base_unit_type_id);

        // При вызове резолвера reguard нужен: $args передаются в $product->update($args),
        // а под unguard все ключи включая 'price' уходят в SQL (колонки нет → ошибка).
        // В проде модель guarded — 'price' фильтруется. В тесте повторяем это поведение.
        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 411.00,
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);
        Model::unguard();

        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->fresh()->base_unit_type_id);
    }

    public function test_existing_base_unit_not_overwritten(): void
    {
        $this->actingAsGlobalAdmin();

        // имитация 1С-товара: база кг зафиксирована, info_weight_type_id уже «грамм» (после кванта)
        $product = Product::create([
            'title' => '1c ' . uniqid(),
            'articul' => 'c-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 411.00,
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Model::unguard();

        // база осталась кг, не перезаписана на грамм
        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->fresh()->base_unit_type_id);
    }

    // --- ProductUpdate: ручная цена авторитетна (откат к 0 у товара без цены из 1С) ---

    /**
     * Весовой 1С-товар без цены из 1С (value=0, price_per_small_unit=0 — как b2b
     * shop 133). Менеджер задаёт цену в карточке → она ДОЛЖНА сохраниться, а не
     * откатиться к нулю. Регресс: applyToProduct(null) брал perSmall=0 из
     * сохранённого price_per_small_unit и затирал введённую цену нулём.
     */
    public function test_manual_price_persists_when_stored_per_small_is_zero(): void
    {
        $this->actingAsGlobalAdmin();

        $product = Product::create([
            'title' => 'no-price ' . uniqid(),
            'articul' => 'np-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
        ]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 0,
            'price_per_small_unit' => 0,
            'price_per_kg' => 0,
            'step' => 1,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 200.00,
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);
        Model::unguard();

        $price = $this->defaultPrice($product);
        $this->assertSame(200.00, (float) $price->value, 'ручная цена весового без цены из 1С должна сохраниться, а не откатиться к 0');
        $this->assertSame(0.2000, (float) $price->price_per_small_unit);
        $this->assertSame(200.00, (float) $price->price_per_kg);
    }

    /**
     * Тот же сценарий, но товар в категории с квантом 250 г: введённое значение —
     * цена за порцию (квант). Она сохраняется, а price_per_small_unit/price_per_kg
     * восстанавливаются от неё (102.75 ₽ / 250 г → 0.411 ₽/г → 411 ₽/кг).
     */
    public function test_manual_price_persists_with_category_quantum(): void
    {
        $this->actingAsGlobalAdmin();

        $cat = $this->leafCategory(250, Type::INFO_WEIGHT_GRAM);
        $product = Product::create([
            'title' => 'no-price-q ' . uniqid(),
            'articul' => 'npq-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
        ]);
        $product->categories()->sync([$cat->id]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 0,
            'price_per_small_unit' => 0,
            'price_per_kg' => 0,
            'step' => 1,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 102.75,
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);
        Model::unguard();

        $price = $this->defaultPrice($product);
        $this->assertSame(102.75, (float) $price->value, 'цена за порцию должна сохраниться');
        $this->assertSame(250, (int) $price->step);
        $this->assertSame(0.4110, (float) $price->price_per_small_unit);
        $this->assertSame(411.00, (float) $price->price_per_kg);
    }

    // --- ProductUpdate: смена шага кванта не должна искажать базовую цену 1С ---

    /**
     * Регресс «битый шаг»: весовой товар (perKg=79, perSmall=0.079, шаг 500,
     * value=39.50 за порцию). Менеджер меняет лишь шаг 500→250, не трогая цену —
     * непофиксенный фронт шлёт прежнюю цену за порцию (39.50, за СТАРЫЙ шаг).
     * Базовая цена 1С НЕ должна удвоиться: perSmall/perKg сохраняются, цена порции
     * пересчитывается по новому шагу (0.079 × 250 = 19.75).
     */
    public function test_step_change_keeps_base_price_when_price_unchanged(): void
    {
        $this->actingAsGlobalAdmin();

        $product = Product::create([
            'title' => 'step-change ' . uniqid(),
            'articul' => 'sc-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
            'manual_quantum_step' => 500,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 39.50,
            'price_per_small_unit' => 0.0790,
            'price_per_kg' => 79.00,
            'step' => 500,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 39.50, // прежняя цена за порцию (за старый шаг 500), цену не меняли
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'manual_quantum_step' => 250,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Model::unguard();

        $price = $this->defaultPrice($product);
        $this->assertSame(79.00, (float) $price->price_per_kg, 'базовая цена 1С не должна удваиваться при смене шага');
        $this->assertSame(0.0790, (float) $price->price_per_small_unit);
        $this->assertSame(250, (int) $price->step);
        $this->assertSame(19.75, (float) $price->value, 'цена порции = perSmall × новый шаг');
    }

    /**
     * Менеджер реально меняет цену продажи (введённое ≠ прежней отображаемой) —
     * базовая цена реконструируется из новой цены за порцию по новому шагу.
     */
    public function test_step_change_with_new_price_reconstructs_base(): void
    {
        $this->actingAsGlobalAdmin();

        $product = Product::create([
            'title' => 'step-newprice ' . uniqid(),
            'articul' => 'snp-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
            'manual_quantum_step' => 500,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 39.50,
            'price_per_small_unit' => 0.0790,
            'price_per_kg' => 79.00,
            'step' => 500,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 60.00, // менеджер задал новую цену за порцию 250 г
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'manual_quantum_step' => 250,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Model::unguard();

        $price = $this->defaultPrice($product);
        $this->assertSame(60.00, (float) $price->value, 'новая цена за порцию сохраняется');
        $this->assertSame(0.2400, (float) $price->price_per_small_unit, '60 / 250 = 0.24 ₽/г');
        $this->assertSame(240.00, (float) $price->price_per_kg);
        $this->assertSame(250, (int) $price->step);
    }

    /**
     * Пофиксенный фронт при смене шага шлёт уже пересчитанную цену за новую
     * порцию (perSmall × новый шаг = 19.75) — базовая цена тоже сохраняется
     * (реконструкция из согласованной цены даёт тот же perSmall).
     */
    public function test_step_change_with_recomputed_portion_price_keeps_base(): void
    {
        $this->actingAsGlobalAdmin();

        $product = Product::create([
            'title' => 'step-recomputed ' . uniqid(),
            'articul' => 'sr-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
            'manual_quantum_step' => 500,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 39.50,
            'price_per_small_unit' => 0.0790,
            'price_per_kg' => 79.00,
            'step' => 500,
        ]);

        Model::reguard();
        app(ProductUpdate::class)(null, [
            'id' => $product->id,
            'price' => 19.75, // фронт пересчитал цену за новую порцию (0.079 × 250)
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'manual_quantum_step' => 250,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
        Model::unguard();

        $price = $this->defaultPrice($product);
        $this->assertSame(79.00, (float) $price->price_per_kg, 'базовая цена сохраняется и при пересчитанной фронтом цене');
        $this->assertSame(0.0790, (float) $price->price_per_small_unit);
        $this->assertSame(19.75, (float) $price->value);
        $this->assertSame(250, (int) $price->step);
    }
}
