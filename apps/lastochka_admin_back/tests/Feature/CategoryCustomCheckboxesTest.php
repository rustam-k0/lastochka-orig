<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CategoryCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Category' => [
                ['field' => 'is_seasonal', 'label' => 'Сезонная', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
    }

    /** @test */
    public function category_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'Категория с чекбоксами ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String, $custom_checkboxes: String) {
                CategoryCreate(title: $title, custom_checkboxes: $custom_checkboxes) { code message }
            }
        ', [
            'title' => $title,
            'custom_checkboxes' => json_encode(['is_seasonal' => 1, 'unknown' => true]),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $category = Category::where('title', $title)->firstOrFail();
        $this->assertSame(['is_seasonal' => true], json_decode($category->custom_checkboxes, true));
    }

    /** @test */
    public function category_update_without_key_keeps_stored_value(): void
    {
        $category = Category::create([
            'title' => 'Кат ' . uniqid(),
            'custom_checkboxes' => json_encode((object) ['is_seasonal' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int, $title: String) {
                CategoryUpdate(id: $id, title: $title) { code message }
            }
        ', ['id' => $category->id, 'title' => 'Переименована ' . uniqid()]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_seasonal' => true], json_decode($category->fresh()->custom_checkboxes, true));
    }
}
