<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Models\UserShop;
use Spatie\Permission\Models\Permission;
use App\Services\Export\ProductExportQuery;
use App\Services\Export\ProductExportWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Выгрузка товаров в XLSX по аналогии с заказами: POST /action/export/products.
 * Либо product_ids (выделенные строки), либо фильтры экрана «Товары».
 */
class ProductExportTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/action/export/products';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin_', Role::ADMIN);
    }

    private function makeUser(string $prefix, string $role): User
    {
        $user = User::create([
            'name' => ucfirst(trim($prefix, '_')),
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid($prefix) . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole(Role::findOrCreate($role));

        return $user;
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code . '_' . uniqid(), 'title' => 'Shop ' . $code]);
    }

    /** fill_percent не fillable (его считает listener) — задаём через forceFill. */
    private function makeProduct(Shop $shop, string $title, array $attrs = []): Product
    {
        return Product::withoutEvents(function () use ($shop, $title, $attrs) {
            $product = new Product();
            $product->forceFill(array_merge([
                'title' => $title,
                'articul' => 'ART-' . uniqid(),
                'shop_id' => $shop->id,
                'status_id' => Status::CODE_ACTIVE,
                'unit_type_id' => Type::UNIT_PIECE,
            ], $attrs));
            $product->save();

            return $product;
        });
    }

    /** Категория сама генерирует link в creating — события не отключаем. */
    private function makeCategory(string $title): Category
    {
        return Category::create(['title' => $title . ' ' . uniqid()]);
    }

    private function makePrice(Product $product, Shop $shop, float $value): Price
    {
        return Price::withoutEvents(fn () => Price::create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $value,
            'step' => 1,
        ]));
    }

    private function makeRemain(Product $product, Shop $shop, float $quantity): Remain
    {
        return Remain::withoutEvents(fn () => Remain::create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => $quantity,
        ]));
    }

    private function attachImg(Product $product): void
    {
        $img = Img::create([
            'path' => '/test.jpg',
            'origin_name' => 't.jpg',
            'alt' => '',
            'size' => 1,
            'type' => 'jpg',
        ]);
        JoinImg::create([
            'item_id' => $product->id,
            'img_id' => $img->id,
            'head_id' => Status::CODE_HEAD_IMG,
            'table_name' => 'products',
        ]);
    }

    /** Кастомная роль под матрицей доступов, опционально с грантом на раздел «Товары». */
    private function makeMatrixUser(string $scopeType, bool $withProductsSection): User
    {
        $role = Role::findOrCreate('export_test_role_' . uniqid());
        $role->scope_type = $scopeType;
        $role->save();

        if ($withProductsSection) {
            $role->givePermissionTo(Permission::findOrCreate('section.products', 'api'));
        }

        return $this->makeUser('matrix_', $role->name);
    }

    private function withMatrix(callable $callback): void
    {
        AdminSetting::query()->updateOrCreate(
            ['key' => 'role_access_matrix_enabled'],
            ['value' => '1']
        );

        try {
            $callback();
        } finally {
            AdminSetting::query()->where('key', 'role_access_matrix_enabled')->update(['value' => '0']);
        }
    }

    /** Скачать выгрузку и распарсить XLSX в массив строк (первая строка — заголовки). */
    private function downloadRows(array $body, ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin, 'api')
            ->postJson(self::URL, $body);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringContainsString('products_', $response->headers->get('content-disposition'));

        $tmp = tempnam(sys_get_temp_dir(), 'products_export_');
        $path = $tmp . '.xlsx';
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
        unlink($tmp);

        return $rows;
    }

    /** Строки данных (без заголовка), сведённые в ассоциативные массивы по заголовкам. */
    private function dataRows(array $rows): array
    {
        $headers = $rows[0];

        return array_map(
            fn ($row) => array_combine($headers, array_pad($row, count($headers), '')),
            array_slice($rows, 1)
        );
    }

    private function findRow(array $rows, Product $product): ?array
    {
        foreach ($this->dataRows($rows) as $row) {
            if ($row['ID'] === (string) $product->id) {
                return $row;
            }
        }

        return null;
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized();
    }

    public function test_courier_is_forbidden(): void
    {
        $courier = $this->makeUser('courier_', Role::COURIER);

        $this->actingAs($courier, 'api')
            ->postJson(self::URL, [])
            ->assertForbidden();
    }

    public function test_invalid_body_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, ['product_ids' => 'not-an-array'])
            ->assertUnprocessable();
    }

    public function test_export_selected_products_by_ids_ignores_filters(): void
    {
        $shop = $this->makeShop('exp');
        $selected = $this->makeProduct($shop, 'Выделенный товар');
        $other = $this->makeProduct($shop, 'Невыделенный товар');

        // title-фильтр указывает на другой товар: при product_ids он игнорируется
        $rows = $this->downloadRows(['product_ids' => [$selected->id], 'title' => 'Невыделенный']);

        $this->assertSame(ProductExportWriter::HEADERS, $rows[0]);
        $this->assertNotNull($this->findRow($rows, $selected));
        $this->assertNull($this->findRow($rows, $other));
        $this->assertCount(1, $this->dataRows($rows));
    }

    public function test_row_contains_table_columns(): void
    {
        $shop = $this->makeShop('exp');
        $category = $this->makeCategory('Молочка');
        $product = $this->makeProduct($shop, 'Молоко 3.2%', [
            'articul' => 'ART-MILK',
            'barcode' => '4600000000001',
            'code' => '1C-0001',
            'fill_percent' => 73,
            'description' => 'Описание',
        ]);
        $product->categories()->attach($category->id, ['position' => 0]);
        $this->makePrice($product, $shop, 89.5);
        $this->makeRemain($product, $shop, 12);

        $row = $this->findRow($this->downloadRows(['product_ids' => [$product->id]]), $product);

        $this->assertNotNull($row);
        $this->assertSame('ART-MILK', $row['Артикул']);
        // Штрихкод и код 1С остаются строками (не превращаются в экспоненту)
        $this->assertSame('4600000000001', $row['Штрихкод']);
        $this->assertSame('1C-0001', $row['Код 1С']);
        $this->assertSame('Молоко 3.2%', $row['Название']);
        $this->assertSame($category->title, $row['Категории']);
        $this->assertSame('Shop exp', $row['Магазин']);
        $this->assertArrayNotHasKey('Тип', $row);
        $this->assertSame('89.5', $row['Стоимость']);
        $this->assertSame('12', $row['Остатки']);

        // Без цены и строк остатков: стоимость пустая (как «-» в таблице), остаток 0 (как remains_sum)
        $empty = $this->makeProduct($shop, 'Без цены и остатков');
        $emptyRow = $this->findRow($this->downloadRows(['product_ids' => [$empty->id]]), $empty);
        $this->assertSame('', $emptyRow['Стоимость']);
        $this->assertSame('0', $emptyRow['Остатки']);
        $this->assertSame('', $row['Фиксированные остатки']);
        $this->assertSame('73', $row['Заполненность, %']);
        $this->assertSame('Активен', $row['Статус']);
        $this->assertSame($product->created_at->format('d.m.Y H:i'), $row['Дата создания']);
    }

    public function test_barcodes_list_is_preferred_over_single_barcode(): void
    {
        $shop = $this->makeShop('exp');
        // barcodes — надмножество barcode (бэкфилл миграции): выгружаем весь список
        $product = $this->makeProduct($shop, 'Мультиштрихкод', [
            'barcode' => '4600000000002',
            'barcodes' => ['4600000000002', '4600000000003'],
        ]);

        $row = $this->findRow($this->downloadRows(['product_ids' => [$product->id]]), $product);

        $this->assertSame('4600000000002, 4600000000003', $row['Штрихкод']);
    }

    public function test_status_labels(): void
    {
        $shop = $this->makeShop('exp');
        $active = $this->makeProduct($shop, 'Активный');
        $hidden = $this->makeProduct($shop, 'Скрытый', ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $rows = $this->downloadRows(['product_ids' => [$active->id, $hidden->id]]);

        $this->assertSame('Активен', $this->findRow($rows, $active)['Статус']);
        $this->assertSame('Не опубликован', $this->findRow($rows, $hidden)['Статус']);
    }

    public function test_fixed_stock_column_only_for_fixed_mode(): void
    {
        $shop = $this->makeShop('exp');
        $fixed = $this->makeProduct($shop, 'Фиксированный', [
            'effective_stock_mode' => 'fixed',
            'effective_fixed_stock' => 50,
        ]);
        $normal = $this->makeProduct($shop, 'Обычный', [
            'effective_stock_mode' => 'normal',
            'effective_fixed_stock' => 50,
        ]);

        $rows = $this->downloadRows(['product_ids' => [$fixed->id, $normal->id]]);

        $this->assertSame('50', $this->findRow($rows, $fixed)['Фиксированные остатки']);
        $this->assertSame('', $this->findRow($rows, $normal)['Фиксированные остатки']);
    }

    public function test_stock_unit_columns(): void
    {
        // Остаток — в базовой единице 1С (кг), фикс-лимит — в наименьшей (г):
        // «12.806» рядом с «5000» без единиц читались как одно и то же.
        $shop = $this->makeShop('exp');
        $weighted = $this->makeProduct($shop, 'Филе', [
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'info_weight' => 300,
            'effective_stock_mode' => 'fixed',
            'effective_fixed_stock' => 5000,
        ]);
        $piece = $this->makeProduct($shop, 'Сыр', [
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $rows = $this->downloadRows(['product_ids' => [$weighted->id, $piece->id]]);

        $this->assertSame('кг', $this->findRow($rows, $weighted)['Ед. остатка']);
        $this->assertSame('г', $this->findRow($rows, $weighted)['Ед. фикс. остатков']);
        $this->assertSame('шт', $this->findRow($rows, $piece)['Ед. остатка']);
        $this->assertSame('', $this->findRow($rows, $piece)['Ед. фикс. остатков']);
    }

    public function test_price_and_remains_follow_selected_shop(): void
    {
        $shopA = $this->makeShop('exp_a');
        $shopB = $this->makeShop('exp_b');
        $product = $this->makeProduct($shopA, 'Сетевой товар');
        $this->makePrice($product, $shopA, 100);
        $this->makePrice($product, $shopB, 200);
        $this->makeRemain($product, $shopA, 3);
        $this->makeRemain($product, $shopB, 7);

        $byShopB = $this->findRow(
            $this->downloadRows(['product_ids' => [$product->id], 'selected_shop_id' => $shopB->id]),
            $product
        );
        $this->assertSame('200', $byShopB['Стоимость']);
        $this->assertSame('7', $byShopB['Остатки']);

        // Без магазина: цена по первой строке (min shop_id), остаток — сумма по сети
        $network = $this->findRow($this->downloadRows(['product_ids' => [$product->id]]), $product);
        $this->assertSame('100', $network['Стоимость']);
        $this->assertSame('10', $network['Остатки']);
    }

    public function test_export_all_applies_screen_filters(): void
    {
        $shop = $this->makeShop('exp');
        $otherShop = $this->makeShop('exp_other');
        $category = $this->makeCategory('Категория');
        $marker = 'ФЛТ' . uniqid();

        $matching = $this->makeProduct($shop, "$marker подходит");
        $matching->categories()->attach($category->id, ['position' => 0]);
        $wrongCategory = $this->makeProduct($shop, "$marker не та категория");
        $wrongShop = $this->makeProduct($otherShop, "$marker не тот магазин");
        $wrongShop->categories()->attach($category->id, ['position' => 0]);
        $wrongTitle = $this->makeProduct($shop, 'Другое название');
        $wrongTitle->categories()->attach($category->id, ['position' => 0]);

        $rows = $this->downloadRows([
            'shop_id' => $shop->id,
            'title' => $marker,
            'categories' => [$category->id],
        ]);

        $this->assertNotNull($this->findRow($rows, $matching));
        $this->assertNull($this->findRow($rows, $wrongCategory));
        $this->assertNull($this->findRow($rows, $wrongShop));
        $this->assertNull($this->findRow($rows, $wrongTitle));
    }

    public function test_export_all_applies_children_and_img_and_fill_filters(): void
    {
        $shop = $this->makeShop('exp');
        $marker = 'ФЛТ' . uniqid();

        $parent = $this->makeProduct($shop, "$marker родитель", ['is_configurable' => true, 'fill_percent' => 90]);
        $child = $this->makeProduct($shop, "$marker вариант", ['parent_id' => $parent->id, 'fill_percent' => 90]);
        $lowFill = $this->makeProduct($shop, "$marker низкая заполненность", ['fill_percent' => 10]);
        $withImg = $this->makeProduct($shop, "$marker с картинкой", ['fill_percent' => 90]);
        $this->attachImg($withImg);

        $rows = $this->downloadRows([
            'shop_id' => $shop->id,
            'title' => $marker,
            'exclude_children' => true,
            'has_img' => false,
            'fill_percent_gte' => 50,
        ]);

        $this->assertNotNull($this->findRow($rows, $parent));
        $this->assertNull($this->findRow($rows, $child));
        $this->assertNull($this->findRow($rows, $lowFill));
        $this->assertNull($this->findRow($rows, $withImg));

        $onlyWithImg = $this->downloadRows(['shop_id' => $shop->id, 'title' => $marker, 'has_img' => true]);
        $this->assertNotNull($this->findRow($onlyWithImg, $withImg));
        $this->assertNull($this->findRow($onlyWithImg, $parent));

        $onlyChildren = $this->downloadRows([
            'shop_id' => $shop->id,
            'title' => $marker,
            'only_children' => true,
        ]);
        $this->assertNotNull($this->findRow($onlyChildren, $child));
        $this->assertNull($this->findRow($onlyChildren, $parent));
    }

    public function test_export_all_respects_ordering(): void
    {
        $shop = $this->makeShop('exp');
        $marker = 'СРТ' . uniqid();
        $b = $this->makeProduct($shop, "$marker Б");
        $a = $this->makeProduct($shop, "$marker А");

        $rows = $this->downloadRows([
            'shop_id' => $shop->id,
            'title' => $marker,
            'ordering' => [['orderColumn' => 'title', 'orderBy' => 'ASC']],
        ]);

        $ids = array_column($this->dataRows($rows), 'ID');
        $this->assertSame([(string) $a->id, (string) $b->id], $ids);
    }

    public function test_shop_user_sees_only_own_shop_products(): void
    {
        $ownShop = $this->makeShop('own');
        $foreignShop = $this->makeShop('foreign');
        $partner = $this->makeUser('partner_', Role::SHOP);
        UserShop::create(['user_id' => $partner->id, 'shop_id' => $ownShop->id]);

        $own = $this->makeProduct($ownShop, 'Свой товар');
        $foreign = $this->makeProduct($foreignShop, 'Чужой товар');

        // Даже по явным product_ids партнёр не получит чужой товар
        $rows = $this->downloadRows(['product_ids' => [$own->id, $foreign->id]], $partner);

        $this->assertNotNull($this->findRow($rows, $own));
        $this->assertNull($this->findRow($rows, $foreign));
    }

    public function test_matrix_denies_role_without_products_section(): void
    {
        $this->withMatrix(function () {
            $user = $this->makeMatrixUser(Role::SCOPE_GLOBAL, false);

            $this->actingAs($user, 'api')
                ->postJson(self::URL, [])
                ->assertForbidden();
        });
    }

    public function test_matrix_allows_global_role_with_products_section(): void
    {
        $this->withMatrix(function () {
            $shop = $this->makeShop('exp');
            $product = $this->makeProduct($shop, 'Товар под матрицей');
            $user = $this->makeMatrixUser(Role::SCOPE_GLOBAL, true);

            $rows = $this->downloadRows(['product_ids' => [$product->id]], $user);

            $this->assertNotNull($this->findRow($rows, $product));
        });
    }

    public function test_matrix_shop_role_sees_only_own_shop(): void
    {
        $this->withMatrix(function () {
            $ownShop = $this->makeShop('own');
            $foreignShop = $this->makeShop('foreign');
            $partner = $this->makeMatrixUser(Role::SCOPE_SHOP, true);
            UserShop::create(['user_id' => $partner->id, 'shop_id' => $ownShop->id]);

            $own = $this->makeProduct($ownShop, 'Свой товар');
            $foreign = $this->makeProduct($foreignShop, 'Чужой товар');

            $rows = $this->downloadRows(['product_ids' => [$own->id, $foreign->id]], $partner);

            $this->assertNotNull($this->findRow($rows, $own));
            $this->assertNull($this->findRow($rows, $foreign));
        });
    }

    public function test_shop_user_without_shop_binding_gets_empty_export(): void
    {
        $shop = $this->makeShop('exp');
        $orphan = $this->makeProduct($shop, 'Товар без владельца', ['shop_id' => null]);
        $partner = $this->makeUser('partner_', Role::SHOP);

        // Партнёр без строки в user_shops: пустая выборка, а не товары с shop_id IS NULL
        $rows = $this->downloadRows(['product_ids' => [$orphan->id]], $partner);

        $this->assertSame([], $this->dataRows($rows));
    }

    public function test_available_in_shop_id_filter_for_network_catalog(): void
    {
        $anchorShop = $this->makeShop('anchor');
        $otherShop = $this->makeShop('other');
        $marker = 'СЕТЬ' . uniqid();

        // Карточка принадлежит якорному магазину, но по ней есть цена в другом
        $inOther = $this->makeProduct($anchorShop, "$marker есть в другом");
        $this->makePrice($inOther, $otherShop, 10);
        $onlyAnchor = $this->makeProduct($anchorShop, "$marker только якорный");

        $rows = $this->downloadRows([
            'available_in_shop_id' => $otherShop->id,
            'selected_shop_id' => $otherShop->id,
            'title' => $marker,
        ]);

        $this->assertNotNull($this->findRow($rows, $inOther));
        $this->assertNull($this->findRow($rows, $onlyAnchor));
    }

    public function test_deleted_shop_title_is_still_exported(): void
    {
        $shop = $this->makeShop('gone');
        $product = $this->makeProduct($shop, 'Товар удалённого магазина');
        $shop->delete();

        $row = $this->findRow($this->downloadRows(['product_ids' => [$product->id]]), $product);

        $this->assertSame('Shop gone', $row['Магазин']);
    }

    public function test_unknown_order_column_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, ['ordering' => [['orderColumn' => 'description', 'orderBy' => 'ASC']]])
            ->assertUnprocessable();
    }

    public function test_is_culinary_filter_is_applied(): void
    {
        $shop = $this->makeShop('exp');
        $marker = 'КУЛ' . uniqid();
        $culinary = $this->makeProduct($shop, "$marker кулинария", ['is_culinary' => true]);
        $plain = $this->makeProduct($shop, "$marker обычный", ['is_culinary' => false]);

        $rows = $this->downloadRows(['shop_id' => $shop->id, 'title' => $marker, 'is_culinary' => true]);

        $this->assertNotNull($this->findRow($rows, $culinary));
        $this->assertNull($this->findRow($rows, $plain));
    }

    public function test_is_new_and_is_hurry_up_filters_are_applied(): void
    {
        $shop = $this->makeShop('exp');
        $marker = 'НОВ' . uniqid();
        $new = $this->makeProduct($shop, "$marker новинка", ['is_new' => true]);
        $hurry = $this->makeProduct($shop, "$marker успей", ['is_hurry_up' => true]);
        $plain = $this->makeProduct($shop, "$marker обычный");

        $rows = $this->downloadRows(['shop_id' => $shop->id, 'title' => $marker, 'is_new' => true]);

        $this->assertNotNull($this->findRow($rows, $new));
        $this->assertNull($this->findRow($rows, $hurry));
        $this->assertNull($this->findRow($rows, $plain));

        $rows = $this->downloadRows(['shop_id' => $shop->id, 'title' => $marker, 'is_hurry_up' => true]);

        $this->assertNotNull($this->findRow($rows, $hurry));
        $this->assertNull($this->findRow($rows, $new));

        // false — тоже фильтр, а не «все»
        $rows = $this->downloadRows(['shop_id' => $shop->id, 'title' => $marker, 'is_new' => false]);

        $this->assertNull($this->findRow($rows, $new));
        $this->assertNotNull($this->findRow($rows, $plain));
    }

    public function test_query_keeps_narrow_select_with_ordering(): void
    {
        $this->actingAs($this->admin, 'api');

        // QuerySorter делает select('products.*') — узкий select выгрузки должен пережить сортировку
        $sql = app(ProductExportQuery::class)
            ->build(['ordering' => [['orderColumn' => 'created_at', 'orderBy' => 'DESC']]])
            ->toSql();

        $this->assertStringNotContainsString('products`.*', $sql);
        $this->assertStringNotContainsString('products.*', $sql);
        $this->assertStringContainsString('`products`.`articul`', $sql);
        $this->assertStringContainsString('order by `created_at` desc', $sql);
    }
}
