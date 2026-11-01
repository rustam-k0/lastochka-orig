<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Services\CustomCheckboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomCheckboxServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Product' => [
                ['field' => 'is_culinary', 'label' => 'Кулинария', 'enabled' => true],
                ['field' => 'is_hit', 'label' => 'Хит продаж', 'enabled' => true, 'custom' => true, 'filterable' => true],
                ['field' => 'is_eco', 'label' => 'Эко', 'enabled' => true, 'custom' => true],
            ],
            'Brand' => [
                ['field' => 'is_premium', 'label' => 'Премиум', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
    }

    /** @test */
    public function custom_fields_returns_only_custom_slugs(): void
    {
        $this->assertSame(['is_hit', 'is_eco'], CustomCheckboxService::customFields('Product'));
        $this->assertSame(['is_premium'], CustomCheckboxService::customFields('Brand'));
        $this->assertSame([], CustomCheckboxService::customFields('Story'));
    }

    /** @test */
    public function sanitize_keeps_known_fields_and_drops_unknown(): void
    {
        $args = CustomCheckboxService::sanitizeArgs('Product', [
            'title' => 'x',
            'custom_checkboxes' => json_encode(['is_hit' => true, 'is_eco' => 0, 'hacker_field' => true]),
        ]);

        $this->assertSame('x', $args['title']);
        $this->assertSame(['is_hit' => true, 'is_eco' => false], json_decode($args['custom_checkboxes'], true));
    }

    /** @test */
    public function sanitize_empty_map_encodes_as_json_object(): void
    {
        $args = CustomCheckboxService::sanitizeArgs('Product', ['custom_checkboxes' => '{}']);
        $this->assertSame('{}', $args['custom_checkboxes']);
    }

    /** @test */
    public function sanitize_without_key_leaves_args_untouched(): void
    {
        $args = CustomCheckboxService::sanitizeArgs('Product', ['title' => 'x']);
        $this->assertSame(['title' => 'x'], $args);
    }

    /** @test */
    public function sanitize_null_and_broken_json_unset_key(): void
    {
        $args = CustomCheckboxService::sanitizeArgs('Product', ['custom_checkboxes' => null]);
        $this->assertArrayNotHasKey('custom_checkboxes', $args);

        $args = CustomCheckboxService::sanitizeArgs('Product', ['custom_checkboxes' => '{broken']);
        $this->assertArrayNotHasKey('custom_checkboxes', $args);
    }

    /** @test */
    public function default_config_contains_all_eight_entities(): void
    {
        $config = CustomCheckboxService::defaultCheckboxConfig();
        foreach (array_keys(CustomCheckboxService::ENTITY_TABLES) as $entity) {
            $this->assertArrayHasKey($entity, $config);
        }
        $this->assertNotEmpty($config['Product']);
        $this->assertSame([], $config['Brand']);
    }

    /** @test */
    public function default_product_config_contains_new_and_hurry_up_checkboxes(): void
    {
        $labels = array_column(CustomCheckboxService::defaultCheckboxConfig()['Product'], 'label', 'field');

        $this->assertSame('Новинка', $labels['is_new'] ?? null);
        $this->assertSame('Успей купить', $labels['is_hurry_up'] ?? null);
    }
}
