<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductReviewEntityDeleteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected ProductReview $review;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса до создания приложения observers регистрируются только
        // в первом тесте процесса и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $shop = Shop::create([
            'title' => 'Review Delete Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $product = Product::create([
            'title' => 'Review Delete Product ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => Status::CODE_ACTIVE,
        ]);

        $this->review = ProductReview::create([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'rating' => 4,
            'is_published' => false,
        ]);
    }

    protected function deleteMutation(): string
    {
        return '
            mutation ($id: Int!, $table: String!) {
                EntityDelete(id: $id, table: $table) {
                    code
                    message
                }
            }
        ';
    }

    /** @test */
    public function admin_can_delete_product_review_via_entity_delete()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->deleteMutation(), [
                'id' => $this->review->id,
                'table' => 'product_reviews',
            ]);

        $response->assertGraphQLErrorFree();

        $this->assertSoftDeleted('product_reviews', ['id' => $this->review->id]);
    }

    /** @test */
    public function entity_delete_publishes_deleted_event_to_outbox()
    {
        $this->actingAs($this->adminUser)
            ->graphQL($this->deleteMutation(), [
                'id' => $this->review->id,
                'table' => 'product_reviews',
            ])
            ->assertGraphQLErrorFree();

        $outbox = DB::table('event_outbox')
            ->where('entity_type', 'product_reviews')
            ->where('entity_id', $this->review->id)
            ->where('routing_key', 'product_review.deleted')
            ->exists();

        $this->assertTrue($outbox, 'Ожидалось событие product_review.deleted в event_outbox (удаление должно доехать до storefront)');
    }
}
