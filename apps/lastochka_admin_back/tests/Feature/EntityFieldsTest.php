<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EntityFieldsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_fields')->delete();
    }

    /** @test */
    public function it_returns_default_config_when_db_empty(): void
    {
        $response = $this->actingAs($this->user)
            ->graphQL('query { entityFields }');

        $response->assertJsonStructure(['data' => ['entityFields']]);
        $json = $response->json('data.entityFields');
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('Shop', $decoded);
        $this->assertNotEmpty($decoded['Shop']);
        $fields = array_column($decoded['Shop'], 'field');
        $this->assertContains('phone', $fields);
        $this->assertContains('description', $fields);
        $this->assertNotContains('title', $fields);
    }

    /** @test */
    public function it_saves_valid_config(): void
    {
        $config = json_encode([
            'Shop' => [
                ['field' => 'phone', 'label' => 'Телефон', 'enabled' => false],
                ['field' => 'description', 'label' => 'Описание', 'enabled' => true],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id key type value } }',
                ['config' => $config]
            );

        $response->assertJsonStructure(['data' => ['saveEntityFields' => ['id', 'key', 'type', 'value']]]);
        $this->assertEquals('entity_fields', $response->json('data.saveEntityFields.key'));
        $this->assertEquals('json', $response->json('data.saveEntityFields.type'));
        $this->assertDatabaseHas('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function repeated_save_updates_same_row(): void
    {
        $config1 = json_encode(['Shop' => [['field' => 'phone', 'label' => 'Телефон', 'enabled' => false]]]);
        $config2 = json_encode(['Shop' => [['field' => 'phone', 'label' => 'Телефон', 'enabled' => true]]]);

        $this->actingAs($this->user)->graphQL(
            'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
            ['config' => $config1]
        );
        $this->actingAs($this->user)->graphQL(
            'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
            ['config' => $config2]
        );

        $this->assertEquals(1, AdminSetting::where('key', 'entity_fields')->count());
        $saved = json_decode(AdminSetting::getValue('entity_fields'), true);
        $this->assertTrue($saved['Shop'][0]['enabled']);
    }

    /** @test */
    public function it_rejects_always_visible_field(): void
    {
        $config = json_encode([
            'Shop' => [
                ['field' => 'title', 'label' => 'Название', 'enabled' => false],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
                ['config' => $config]
            );

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function it_rejects_invalid_structure_missing_enabled(): void
    {
        $config = json_encode([
            'Shop' => [
                ['field' => 'phone', 'label' => 'Телефон'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
                ['config' => $config]
            );

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function it_rejects_unknown_entity(): void
    {
        $config = json_encode([
            'User' => [
                ['field' => 'email', 'label' => 'Email', 'enabled' => false],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
                ['config' => $config]
            );

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function it_rejects_non_array_fields(): void
    {
        $config = json_encode(['Shop' => 'not-an-array']);

        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
                ['config' => $config]
            );

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function it_rejects_invalid_json(): void
    {
        $response = $this->actingAs($this->user)
            ->graphQL(
                'mutation ($config: String!) { saveEntityFields(config: $config) { id } }',
                ['config' => 'not-json{']
            );

        $response->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseMissing('admin_settings', ['key' => 'entity_fields']);
    }

    /** @test */
    public function unauthenticated_request_is_rejected(): void
    {
        $response = $this->graphQL('query { entityFields }');
        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function default_config_includes_category_background_img(): void
    {
        $response = $this->actingAs($this->user)->graphQL('query { entityFields }');
        $decoded = json_decode($response->json('data.entityFields'), true);

        $this->assertArrayHasKey('Category', $decoded);
        $fields = array_column($decoded['Category'], 'field');
        $this->assertContains('background_img', $fields);
    }

    /** @test */
    public function it_saves_category_config(): void
    {
        $config = json_encode([
            'Category' => [
                ['field' => 'background_img', 'label' => 'Фоновое изображение', 'enabled' => false],
            ],
        ]);

        $response = $this->actingAs($this->user)->graphQL(
            'mutation ($config: String!) { saveEntityFields(config: $config) { id key type value } }',
            ['config' => $config]
        );

        $response->assertJsonStructure(['data' => ['saveEntityFields' => ['id', 'key', 'type', 'value']]]);
        $this->assertDatabaseHas('admin_settings', ['key' => 'entity_fields']);
    }
}
