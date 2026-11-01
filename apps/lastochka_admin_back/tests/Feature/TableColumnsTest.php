<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class TableColumnsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'table_columns')->delete();
    }

    private function query(): array
    {
        $response = $this->actingAs($this->user)->graphQL('query { tableColumns }');

        return json_decode($response->json('data.tableColumns'), true);
    }

    private function save(string $config)
    {
        return $this->actingAs($this->user)->graphQL(
            'mutation ($config: String!) { saveTableColumns(config: $config) { id key type value } }',
            ['config' => $config]
        );
    }

    /** @test */
    public function it_returns_default_config_when_db_empty(): void
    {
        $decoded = $this->query();

        $this->assertArrayHasKey('products', $decoded);
        $this->assertArrayHasKey('orders', $decoded);

        $productFields = array_column($decoded['products'], 'field');
        $this->assertContains('title', $productFields);
        $this->assertContains('remains', $productFields);
        $this->assertContains('fixed_stock', $productFields);

        $orderFields = array_column($decoded['orders'], 'field');
        $this->assertContains('created_at', $orderFields);
        $this->assertContains('amount', $orderFields);
    }

    /** @test */
    public function fixed_stock_column_follows_remains_by_default(): void
    {
        $fields = array_column($this->query()['products'], 'field');

        $this->assertSame(
            array_search('remains', $fields, true) + 1,
            array_search('fixed_stock', $fields, true)
        );
    }

    /** @test */
    public function address_column_follows_client_by_default(): void
    {
        // Колонка адреса нужна рядом с покупателем: операторы опознают заказ
        // по адресу, а не по телефону.
        $fields = array_column($this->query()['orders'], 'field');

        $this->assertContains('address', $fields);
        $this->assertSame(
            array_search('client', $fields, true) + 1,
            array_search('address', $fields, true)
        );
    }

    /** @test */
    public function every_default_column_is_enabled(): void
    {
        $decoded = $this->query();

        foreach (['products', 'orders'] as $table) {
            foreach ($decoded[$table] as $column) {
                $this->assertTrue($column['enabled'], "Колонка {$table}.{$column['field']} выключена по умолчанию");
                $this->assertNotEmpty($column['label']);
            }
        }
    }

    /** @test */
    public function it_saves_valid_config(): void
    {
        $config = json_encode([
            'products' => [
                ['field' => 'title', 'label' => 'Название', 'enabled' => true],
                ['field' => 'remains', 'label' => 'Остатки', 'enabled' => false],
            ],
        ]);

        $response = $this->save($config);

        $response->assertJsonStructure(['data' => ['saveTableColumns' => ['id', 'key', 'type', 'value']]]);
        $this->assertEquals('table_columns', $response->json('data.saveTableColumns.key'));
        $this->assertEquals('json', $response->json('data.saveTableColumns.type'));
        $this->assertDatabaseHas('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function repeated_save_updates_same_row(): void
    {
        $this->save(json_encode([
            'products' => [['field' => 'remains', 'label' => 'Остатки', 'enabled' => false]],
        ]));
        $this->save(json_encode([
            'products' => [['field' => 'remains', 'label' => 'Остатки', 'enabled' => true]],
        ]));

        $this->assertEquals(1, AdminSetting::where('key', 'table_columns')->count());
        $saved = json_decode(AdminSetting::getValue('table_columns'), true);
        $this->assertTrue($saved['products'][0]['enabled']);
    }

    /** @test */
    public function stored_order_wins_over_defaults(): void
    {
        $this->save(json_encode([
            'products' => [
                ['field' => 'fixed_stock', 'label' => 'Фиксированные остатки', 'enabled' => true],
                ['field' => 'title', 'label' => 'Название', 'enabled' => true],
            ],
        ]));

        $fields = array_column($this->query()['products'], 'field');

        $this->assertSame('fixed_stock', $fields[0]);
        $this->assertSame('title', $fields[1]);
    }

    /** @test */
    public function columns_missing_from_stored_config_are_appended_from_defaults(): void
    {
        // Блоб сохранён до появления новой колонки — она обязана доехать,
        // иначе фича невидима на стенде с уже сохранёнными настройками.
        $this->save(json_encode([
            'products' => [['field' => 'title', 'label' => 'Название', 'enabled' => true]],
        ]));

        $decoded = $this->query();
        $fields = array_column($decoded['products'], 'field');

        $this->assertContains('fixed_stock', $fields);
        $this->assertContains('remains', $fields);
        $this->assertSame('title', $fields[0]);
        $this->assertArrayHasKey('orders', $decoded);
        $this->assertNotEmpty($decoded['orders']);
    }

    /** @test */
    public function unknown_stored_column_is_dropped(): void
    {
        AdminSetting::setValue('table_columns', json_encode([
            'products' => [
                ['field' => 'title', 'label' => 'Название', 'enabled' => true],
                ['field' => 'legacy_field', 'label' => 'Удалённая колонка', 'enabled' => true],
            ],
        ]), 'json');

        $fields = array_column($this->query()['products'], 'field');

        $this->assertNotContains('legacy_field', $fields);
    }

    /** @test */
    public function stored_label_does_not_override_default(): void
    {
        // Заголовки колонок живут в коде фронта: блоб хранит label только для
        // читаемости настроек, переименование через него не поддерживается.
        $this->save(json_encode([
            'products' => [['field' => 'remains', 'label' => 'Что-то своё', 'enabled' => true]],
        ]));

        $column = collect($this->query()['products'])->firstWhere('field', 'remains');

        $this->assertSame('Остатки', $column['label']);
    }

    /** @test */
    public function system_columns_are_marked_in_response(): void
    {
        $columns = collect($this->query()['products']);

        $this->assertTrue($columns->firstWhere('field', 'select')['system']);
        $this->assertFalse($columns->firstWhere('field', 'remains')['system']);
    }

    /** @test */
    public function it_rejects_hidden_system_column(): void
    {
        $response = $this->save(json_encode([
            'orders' => [['field' => 'action', 'label' => 'Действия', 'enabled' => false]],
        ]));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_unknown_table(): void
    {
        $response = $this->save(json_encode([
            'clients' => [['field' => 'phone', 'label' => 'Телефон', 'enabled' => true]],
        ]));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_unknown_column(): void
    {
        $response = $this->save(json_encode([
            'products' => [['field' => 'no_such_column', 'label' => 'Нет такой', 'enabled' => true]],
        ]));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_column_without_enabled(): void
    {
        $response = $this->save(json_encode([
            'products' => [['field' => 'title', 'label' => 'Название']],
        ]));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_non_array_table(): void
    {
        $response = $this->save(json_encode(['products' => 'not-an-array']));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_invalid_json(): void
    {
        $response = $this->save('not-json{');

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function it_rejects_duplicated_column(): void
    {
        $response = $this->save(json_encode([
            'products' => [
                ['field' => 'title', 'label' => 'Название', 'enabled' => true],
                ['field' => 'title', 'label' => 'Название', 'enabled' => false],
            ],
        ]));

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }

    /** @test */
    public function unauthenticated_request_is_rejected(): void
    {
        $this->graphQL('query { tableColumns }')->assertJsonStructure(['errors']);
    }

    /** @test */
    public function corrupted_stored_value_falls_back_to_defaults(): void
    {
        AdminSetting::setValue('table_columns', 'broken{', 'json');

        $decoded = $this->query();

        $this->assertArrayHasKey('products', $decoded);
        $this->assertContains('fixed_stock', array_column($decoded['products'], 'field'));
    }

    /** @test */
    public function hiding_service_column_returns_client_error(): void
    {
        $response = $this->save(json_encode([
            'orders' => [['field' => 'action', 'label' => 'Действия', 'enabled' => false]],
        ]));

        // Отказ валидации должен доезжать до админки текстом причины, а не
        // «Internal server error»: иначе пользователь не понимает, что случилось,
        // а штатный отказ попадает в трекер ошибок как сбой сервера.
        $response->assertJsonStructure(['errors']);
        $this->assertStringContainsString(
            'служебная',
            $response->json('errors.0.message')
        );
        $this->assertDatabaseMissing('admin_settings', ['key' => 'table_columns']);
    }
}
