<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ProductGroup;
use App\Models\ProductGroupFavorite;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProductGroupFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->user = User::factory()->create();
    }

    public function test_toggle_adds_to_favorites(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create();

        $response = $this->actingAs($this->user)->postJson('/api/v1/favorites/product-groups/toggle', [
            'productGroupId' => $group->id,
        ]);

        $response->assertOk()
            ->assertJson(['isFavorite' => true]);

        $this->assertDatabaseHas('product_group_favorites', [
            'user_id' => $this->user->id,
            'product_group_id' => $group->id,
        ]);
    }

    public function test_toggle_adds_when_concurrent_request_already_added(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create();

        // Параллельный toggle (двойной тап) успевает вставить строку между
        // проверкой «записи нет» и нашим INSERT.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $group): void {
            if ($raced || ! str_starts_with($query->sql, 'select * from "product_group_favorites"')) {
                return;
            }
            $raced = true;
            DB::table('product_group_favorites')->insert([
                'user_id' => $this->user->id,
                'product_group_id' => $group->id,
                'created_at' => now(),
            ]);
        });

        $response = $this->actingAs($this->user)->postJson('/api/v1/favorites/product-groups/toggle', [
            'productGroupId' => $group->id,
        ]);

        $response->assertOk()
            ->assertJson(['isFavorite' => true]);

        $this->assertSame(1, ProductGroupFavorite::where('user_id', $this->user->id)
            ->where('product_group_id', $group->id)
            ->count());
    }

    public function test_toggle_removes_from_favorites(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create();
        ProductGroupFavorite::create([
            'user_id' => $this->user->id,
            'product_group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/favorites/product-groups/toggle', [
            'productGroupId' => $group->id,
        ]);

        $response->assertOk()
            ->assertJson(['isFavorite' => false]);

        $this->assertDatabaseMissing('product_group_favorites', [
            'user_id' => $this->user->id,
            'product_group_id' => $group->id,
        ]);
    }

    public function test_list_favorites(): void
    {
        $groupA = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $groupB = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);

        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $groupA->id]);
        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $groupB->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/favorites/product-groups');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_list_excludes_inactive_groups(): void
    {
        $active = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $inactive = ProductGroup::factory()->for($this->store)->create(['is_active' => false]);

        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $active->id]);
        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $inactive->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/favorites/product-groups');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_list_sorted_date_added_desc_by_default(): void
    {
        $groupA = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $groupB = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);

        $this->createFavoriteAt($groupA->id, now()->subDay());
        $this->createFavoriteAt($groupB->id, now());

        $response = $this->actingAs($this->user)->getJson('/api/v1/favorites/product-groups');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $groupB->id)
            ->assertJsonPath('data.1.id', $groupA->id);
    }

    public function test_list_sorted_date_added_asc(): void
    {
        $groupA = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $groupB = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);

        $this->createFavoriteAt($groupA->id, now()->subDay());
        $this->createFavoriteAt($groupB->id, now());

        $response = $this->actingAs($this->user)->getJson('/api/v1/favorites/product-groups?sort=date_added_asc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $groupA->id)
            ->assertJsonPath('data.1.id', $groupB->id);
    }

    public function test_list_sorted_date_added_desc_explicit(): void
    {
        $groupA = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $groupB = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);

        $this->createFavoriteAt($groupA->id, now()->subDay());
        $this->createFavoriteAt($groupB->id, now());

        $response = $this->actingAs($this->user)->getJson('/api/v1/favorites/product-groups?sort=date_added_desc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $groupB->id)
            ->assertJsonPath('data.1.id', $groupA->id);
    }

    private function createFavoriteAt(int $productGroupId, \Illuminate\Support\Carbon $createdAt): ProductGroupFavorite
    {
        $favorite = ProductGroupFavorite::create([
            'user_id' => $this->user->id,
            'product_group_id' => $productGroupId,
        ]);
        // created_at не в fillable, booted() ставит now() — переписываем напрямую
        $favorite->created_at = $createdAt;
        $favorite->save();

        return $favorite;
    }

    public function test_is_favorite_in_product_group_resource(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $group->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/product-groups/{$group->id}");

        $response->assertOk()
            ->assertJsonPath('data.isFavorite', true);
    }

    public function test_batch_remove_group_favorites(): void
    {
        $groupA = ProductGroup::factory()->for($this->store)->create();
        $groupB = ProductGroup::factory()->for($this->store)->create();
        $groupC = ProductGroup::factory()->for($this->store)->create();

        foreach ([$groupA, $groupB, $groupC] as $group) {
            ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $group->id]);
        }

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => [$groupA->id, $groupB->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 2);

        $this->assertDatabaseMissing('product_group_favorites', ['user_id' => $this->user->id, 'product_group_id' => $groupA->id]);
        $this->assertDatabaseMissing('product_group_favorites', ['user_id' => $this->user->id, 'product_group_id' => $groupB->id]);
        $this->assertDatabaseHas('product_group_favorites', ['user_id' => $this->user->id, 'product_group_id' => $groupC->id]);
    }

    public function test_batch_remove_group_favorites_idempotent_and_scoped_to_user(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create();
        $otherUser = User::factory()->create();

        ProductGroupFavorite::create(['user_id' => $this->user->id, 'product_group_id' => $group->id]);
        ProductGroupFavorite::create(['user_id' => $otherUser->id, 'product_group_id' => $group->id]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => [$group->id, 99999],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 1);

        // Чужая запись не тронута, повтор идемпотентен
        $this->assertDatabaseHas('product_group_favorites', ['user_id' => $otherUser->id, 'product_group_id' => $group->id]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => [$group->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 0);
    }

    public function test_batch_remove_group_favorites_validation(): void
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', []);
        $response->assertUnprocessable()->assertJsonValidationErrors(['productGroupIds']);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => [],
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['productGroupIds']);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => ['abc'],
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['productGroupIds.0']);
    }

    public function test_batch_remove_group_favorites_requires_auth(): void
    {
        $response = $this->deleteJson('/api/v1/favorites/product-groups', [
            'productGroupIds' => [1],
        ]);
        $response->assertUnauthorized();
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/favorites/product-groups');
        $response->assertUnauthorized();
    }
}
