<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * products:normalize-synthetic-articuls — возврат оригинальных sku товарам
 * с синтетическим `1C-{sku}-{hash8}[-counter]` (наследие глобальной проверки
 * коллизий). Переименование только там, где не нарушается
 * UNIQUE(shop_id, articul): дубль-группы и занятые sku остаются как есть.
 */
class NormalizeSyntheticArticulsTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::query()->create([
            'title' => 'Normalize shop',
            'code' => 'NRM_' . Str::random(8),
        ]);
    }

    private function createProduct(string $articul, ?int $shopId = null): Product
    {
        return Product::query()->create([
            'title' => 'fixture ' . $articul,
            'articul' => $articul,
            'shop_id' => $shopId ?? $this->shop->id,
        ]);
    }

    public function test_single_synthetic_articul_is_renamed_to_original(): void
    {
        $product = $this->createProduct('1C-000-0020829-b5106010');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame('000-0020829', $product->fresh()->articul);
    }

    public function test_counter_suffix_is_stripped(): void
    {
        $product = $this->createProduct('1C-000-0019884-a474d78f-22');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame('000-0019884', $product->fresh()->articul);
    }

    public function test_real_intra_shop_duplicate_group_is_kept_synthetic(): void
    {
        $first = $this->createProduct('1C-DUP-SKU-aaaa1111');
        $second = $this->createProduct('1C-DUP-SKU-bbbb2222');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame('1C-DUP-SKU-aaaa1111', $first->fresh()->articul);
        $this->assertSame('1C-DUP-SKU-bbbb2222', $second->fresh()->articul);
    }

    public function test_occupied_original_sku_in_same_shop_is_kept_synthetic(): void
    {
        $holder = $this->createProduct('OCC-SKU');
        $synthetic = $this->createProduct('1C-OCC-SKU-cccc3333');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame('OCC-SKU', $holder->fresh()->articul);
        $this->assertSame('1C-OCC-SKU-cccc3333', $synthetic->fresh()->articul);
    }

    public function test_same_original_sku_in_other_shop_does_not_block_rename(): void
    {
        $otherShop = Shop::query()->create([
            'title' => 'Другой магазин',
            'code' => 'NRM_' . Str::random(8),
        ]);
        $this->createProduct('CROSS-SKU', $otherShop->id);
        $synthetic = $this->createProduct('1C-CROSS-SKU-dddd4444');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame(
            'CROSS-SKU',
            $synthetic->fresh()->articul,
            'UNIQUE действует в пределах магазина: sku в чужом магазине не блокирует возврат оригинала.'
        );
    }

    public function test_unparseable_1c_prefixed_articul_is_left_alone(): void
    {
        $product = $this->createProduct('1C-weird');

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame('1C-weird', $product->fresh()->articul);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->createProduct('1C-DRY-SKU-eeee5555');

        $this->artisan('products:normalize-synthetic-articuls', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('1C-DRY-SKU-eeee5555', $product->fresh()->articul);
    }

    public function test_soft_deleted_synthetic_is_normalized_too(): void
    {
        $product = $this->createProduct('1C-TRASH-SKU-ffff6666');
        $product->delete();

        $this->artisan('products:normalize-synthetic-articuls')->assertSuccessful();

        $this->assertSame(
            'TRASH-SKU',
            Product::withTrashed()->findOrFail($product->id)->articul
        );
    }
}
