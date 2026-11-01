<?php

namespace Tests\Feature;

use App\Models\AggregatorQuarantine;
use App\Models\OneCProblemProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Экспорт «Карантина товаров» в XLSX с учётом фильтров причина+магазин
 * (FOODLINK-11194): GET /action/export/quarantine.
 */
class QuarantineExportTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/action/export/quarantine';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole(Role::findOrCreate(Role::ADMIN));
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => 'Shop ' . $code]);
    }

    private function makeProduct(Shop $shop, string $title, string $articul, ?string $barcode = null): Product
    {
        return Product::withoutEvents(fn () => Product::create([
            'title' => $title,
            'articul' => $articul,
            'barcode' => $barcode,
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
    }

    private function makeQuarantine(Shop $shop, Product $product, string $reason = AggregatorQuarantine::REASON_NO_EAN): AggregatorQuarantine
    {
        return AggregatorQuarantine::create([
            'shop_id' => $shop->id,
            'local_product_id' => $product->id,
            'incoming_title' => $product->title,
            'reason' => $reason,
        ]);
    }

    private function makeOneCProblem(Shop $shop, string $code, string $name, string $sku, ?string $barcode = null): OneCProblemProduct
    {
        return OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => $code,
            'external_id' => 'guid-' . uniqid(),
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
        ]);
    }

    /** Скачать выгрузку и распарсить XLSX в массив строк. */
    private function downloadRows(array $query): array
    {
        $response = $this->actingAs($this->admin, 'api')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(self::URL . '?' . http_build_query($query));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'quarantine_export_') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $rows = [];
        $reader = new Reader();
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($cell) => (string) $cell->getValue(), $row->getCells());
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::create([
            'name' => 'Courier',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('courier_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::COURIER));

        $this->actingAs($user, 'api')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(self::URL)
            ->assertForbidden();
    }

    public function test_invalid_code_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(self::URL . '?code=garbage')
            ->assertUnprocessable();
    }

    public function test_export_aggregator_quarantine_respects_shop_filter(): void
    {
        $shopA = $this->makeShop('exp_shop_a');
        $shopB = $this->makeShop('exp_shop_b');

        $productA = $this->makeProduct($shopA, 'Товар А', 'ART-A');
        $productB = $this->makeProduct($shopB, 'Товар Б', 'ART-B');
        $this->makeQuarantine($shopA, $productA);
        $this->makeQuarantine($shopB, $productB);

        $rows = $this->downloadRows(['code' => '__all__', 'shop_id' => $shopA->id]);

        $this->assertSame(\App\Services\QuarantineExportService::HEADERS, $rows[0]);
        $titles = array_column(array_slice($rows, 1), 1);
        $this->assertContains('Товар А', $titles);
        $this->assertNotContains('Товар Б', $titles);

        $rowA = collect(array_slice($rows, 1))->first(fn ($r) => $r[1] === 'Товар А');
        $this->assertSame((string) $productA->id, $rowA[0]);
        $this->assertSame('Shop exp_shop_a', $rowA[2]);
        $this->assertSame('ART-A', $rowA[3]);
    }

    public function test_export_one_c_code_respects_shop_filter(): void
    {
        $shopA = $this->makeShop('exp_shop_a');
        $shopB = $this->makeShop('exp_shop_b');

        $problemA = $this->makeOneCProblem($shopA, 'missing_image', 'Без картинки А', 'SKU-A', '4600000000001');
        $this->makeOneCProblem($shopB, 'missing_image', 'Без картинки Б', 'SKU-B');
        $this->makeOneCProblem($shopA, 'missing_price', 'Без цены А', 'SKU-P');

        $rows = $this->downloadRows(['code' => 'missing_image', 'shop_id' => $shopA->id]);

        $data = array_slice($rows, 1);
        $titles = array_column($data, 1);
        $this->assertContains('Без картинки А', $titles);
        $this->assertNotContains('Без картинки Б', $titles);
        $this->assertNotContains('Без цены А', $titles);

        $rowA = collect($data)->first(fn ($r) => $r[1] === 'Без картинки А');
        $this->assertSame($problemA->external_id, $rowA[0]);
        $this->assertSame('SKU-A', $rowA[3]);
        // Штрихкод остаётся строкой (не превращается в число/экспоненту).
        $this->assertSame('4600000000001', $rowA[4]);
    }

    public function test_export_no_barcode_merges_both_sources(): void
    {
        $shop = $this->makeShop('exp_shop_union');

        $product = $this->makeProduct($shop, 'Карантинный товар', 'ART-Q');
        $this->makeQuarantine($shop, $product);
        $this->makeOneCProblem($shop, 'missing_barcode', 'Товар 1С без ШК', 'SKU-1C');
        // Чужая причина 1С не должна попасть в union-выгрузку.
        $this->makeOneCProblem($shop, 'missing_price', 'Товар 1С без цены', 'SKU-NP');

        $rows = $this->downloadRows(['code' => 'no_barcode', 'shop_id' => $shop->id]);

        $titles = array_column(array_slice($rows, 1), 1);
        $this->assertContains('Карантинный товар', $titles);
        $this->assertContains('Товар 1С без ШК', $titles);
        $this->assertNotContains('Товар 1С без цены', $titles);
    }

    public function test_export_without_filters_returns_all_aggregator_reasons(): void
    {
        $shop = $this->makeShop('exp_shop_all');

        $noEan = $this->makeProduct($shop, 'Без штрихкода', 'ART-1');
        $doubleMatch = $this->makeProduct($shop, 'Двойное совпадение', 'ART-2', '4600000000002');
        $this->makeQuarantine($shop, $noEan);
        $this->makeQuarantine($shop, $doubleMatch, AggregatorQuarantine::REASON_DOUBLE_MATCH);

        $rows = $this->downloadRows([]);

        $titles = array_column(array_slice($rows, 1), 1);
        $this->assertContains('Без штрихкода', $titles);
        $this->assertContains('Двойное совпадение', $titles);

        $rowDouble = collect(array_slice($rows, 1))->first(fn ($r) => $r[1] === 'Двойное совпадение');
        $this->assertSame('4600000000002', $rowDouble[4]);
    }

    public function test_resolved_quarantine_rows_are_excluded(): void
    {
        $shop = $this->makeShop('exp_shop_res');

        $product = $this->makeProduct($shop, 'Разрешённый', 'ART-R');
        $this->makeQuarantine($shop, $product)->update(['resolved_at' => now()]);

        $rows = $this->downloadRows(['code' => '__all__', 'shop_id' => $shop->id]);

        $this->assertNotContains('Разрешённый', array_column(array_slice($rows, 1), 1));
    }
}
