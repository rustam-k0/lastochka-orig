<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EntityCheckboxesCustomTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $user;

    private const SAVE = '
        mutation SaveEntityCheckboxes($config: String!) {
            saveEntityCheckboxes(config: $config) { id key value type }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
    }

    /** @test */
    public function it_saves_custom_checkbox_for_new_entity_brand(): void
    {
        $config = json_encode([
            'Brand' => [
                ['field' => 'is_premium', 'label' => 'Премиум', 'enabled' => true, 'custom' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);

        $response->assertJsonPath('data.saveEntityCheckboxes.key', 'entity_checkboxes');
        $stored = json_decode(AdminSetting::getValue('entity_checkboxes'), true);
        $this->assertTrue($stored['Brand'][0]['custom']);
    }

    /** @test */
    public function it_saves_filterable_flag_for_product_custom_checkbox(): void
    {
        $config = json_encode([
            'Product' => [
                ['field' => 'is_hit', 'label' => 'Хит продаж', 'enabled' => true, 'custom' => true, 'filterable' => true],
            ],
        ]);

        $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config])
            ->assertJsonPath('data.saveEntityCheckboxes.key', 'entity_checkboxes');

        $stored = json_decode(AdminSetting::getValue('entity_checkboxes'), true);
        $this->assertTrue($stored['Product'][0]['filterable']);
    }

    /** @test */
    public function it_rejects_invalid_slug(): void
    {
        $config = json_encode([
            'Product' => [
                ['field' => 'Хит-Продаж', 'label' => 'Хит', 'enabled' => true, 'custom' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function it_rejects_reserved_field_matching_table_column(): void
    {
        // NB: в products этого форка (electrohub_admin_back) нет колонки `price`
        // (цена хранится в product_offers, мультивендорная модель офферов по
        // магазинам) — используем реально существующую колонку `weight`, чтобы
        // тест проверял намерение (коллизия с колонкой таблицы), а не выдуманное поле.
        $config = json_encode([
            'Product' => [
                ['field' => 'weight', 'label' => 'Вес', 'enabled' => true, 'custom' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function it_rejects_reserved_field_matching_builtin_checkbox(): void
    {
        $config = json_encode([
            'Product' => [
                ['field' => 'is_culinary', 'label' => 'Дубль builtin', 'enabled' => true, 'custom' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function it_rejects_duplicate_fields_within_entity(): void
    {
        $config = json_encode([
            'Brand' => [
                ['field' => 'is_premium', 'label' => 'Премиум', 'enabled' => true, 'custom' => true],
                ['field' => 'is_premium', 'label' => 'Дубль', 'enabled' => true, 'custom' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function it_rejects_unknown_entity(): void
    {
        $config = json_encode(['Order' => []]);
        $response = $this->actingAs($this->user)->graphQL(self::SAVE, ['config' => $config]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function query_merges_defaults_with_stored_blob(): void
    {
        // Старый блоб: только 4 сущности, у Product кастомный чекбокс
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Product' => [
                ['field' => 'is_hit', 'label' => 'Хит', 'enabled' => true, 'custom' => true, 'filterable' => true],
            ],
            'Category' => [],
            'MerchantProductGroup' => [],
            'Story' => [],
        ]), 'json');

        $response = $this->actingAs($this->user)->graphQL('query { entityCheckboxes }');
        $decoded = json_decode($response->json('data.entityCheckboxes'), true);

        // Новые сущности появились из дефолтов
        $this->assertArrayHasKey('Brand', $decoded);
        $this->assertArrayHasKey('MerchantBanner', $decoded);
        $this->assertArrayHasKey('MerchantSale', $decoded);
        $this->assertArrayHasKey('Shop', $decoded);
        // Сохранённое содержимое приоритетно
        $this->assertSame('is_hit', $decoded['Product'][0]['field']);
    }
}
