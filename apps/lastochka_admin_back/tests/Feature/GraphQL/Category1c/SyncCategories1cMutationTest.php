<?php

namespace Tests\Feature\GraphQL\Category1c;

use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category1cSyncBatch;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тесты GraphQL mutation `sync_categories_1c`.
 * См. spec §5.4.
 */
class SyncCategories1cMutationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
        $this->shop = Shop::firstOrFail();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function sync_creates_pending_batch_and_dispatches_job(): void
    {
        Queue::fake();

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($shopId: Int!) {
                sync_categories_1c(shop_id: $shopId) { id status shop_id }
            }
        ', ['shopId' => $this->shop->id]);

        $resp->assertJsonPath('data.sync_categories_1c.status', 'PENDING');
        $batchId = (int) $resp->json('data.sync_categories_1c.id');
        $this->assertGreaterThan(0, $batchId);

        $this->assertDatabaseHas('category_1c_sync_batches', [
            'id' => $batchId,
            'shop_id' => $this->shop->id,
            'status' => 'PENDING',
        ]);

        Queue::assertPushed(SyncCategoryMappingsJob::class, function ($job) use ($batchId) {
            return $job->batchId === $batchId && $job->shopId === $this->shop->id;
        });
    }

    /** @test */
    public function sync_returns_existing_in_progress_batch_and_does_not_dispatch_again(): void
    {
        Queue::fake();

        $existing = Category1cSyncBatch::create([
            'shop_id' => $this->shop->id,
            'status' => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($shopId: Int!) {
                sync_categories_1c(shop_id: $shopId) { id status }
            }
        ', ['shopId' => $this->shop->id]);

        $this->assertEquals($existing->id, (int) $resp->json('data.sync_categories_1c.id'));
        $resp->assertJsonPath('data.sync_categories_1c.status', 'IN_PROGRESS');

        Queue::assertNotPushed(SyncCategoryMappingsJob::class);
    }

    /** @test */
    public function sync_creates_new_batch_after_completed_batch(): void
    {
        Queue::fake();

        Category1cSyncBatch::create([
            'shop_id' => $this->shop->id,
            'status' => 'COMPLETED',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(50),
        ]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($shopId: Int!) {
                sync_categories_1c(shop_id: $shopId) { id status }
            }
        ', ['shopId' => $this->shop->id]);

        $resp->assertJsonPath('data.sync_categories_1c.status', 'PENDING');
        Queue::assertPushed(SyncCategoryMappingsJob::class, 1);
    }
}
