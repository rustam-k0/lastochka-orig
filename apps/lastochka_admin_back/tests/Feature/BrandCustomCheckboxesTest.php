<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class BrandCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Brand' => [
                ['field' => 'is_premium', 'label' => 'Премиум', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
    }

    /** @test */
    public function brand_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'Бренд ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String!, $logo_image: ImgInput!, $custom_checkboxes: String) {
                BrandCreate(title: $title, logo_image: $logo_image, custom_checkboxes: $custom_checkboxes) { id title }
            }
        ', [
            'title' => $title,
            'logo_image' => [
                'path' => '/files/test/logo.png',
                'origin_name' => 'logo.png',
                'alt' => 'logo',
                'size' => '10',
                'type' => 'image/png',
            ],
            'custom_checkboxes' => json_encode(['is_premium' => true, 'unknown' => 1]),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $brand = Brand::where('title', $title)->firstOrFail();
        $this->assertSame(['is_premium' => true], json_decode($brand->custom_checkboxes, true));
    }

    /** @test */
    public function brand_update_without_key_keeps_stored_value(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'position' => 0,
            'is_active' => true,
            'custom_checkboxes' => json_encode((object) ['is_premium' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $title: String) {
                BrandUpdate(id: $id, title: $title) { id title }
            }
        ', ['id' => $brand->id, 'title' => 'Ребрендинг ' . uniqid()]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_premium' => true], json_decode($brand->fresh()->custom_checkboxes, true));
    }
}
