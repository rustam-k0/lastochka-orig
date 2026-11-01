<?php

namespace Tests\Feature;

use App\Models\ProductReplaceReason;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductReplaceReasonTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();
    }

    protected function createReason(array $attributes = []): ProductReplaceReason
    {
        return ProductReplaceReason::create(array_merge([
            'title' => 'Test Replace Reason ' . uniqid(),
            'is_active' => true,
            'position' => 0,
        ], $attributes));
    }

    /** @test */
    public function it_can_create_product_replace_reason()
    {
        $mutation = '
            mutation ProductReplaceReasonCreate($title: String!, $is_active: Boolean, $position: Int) {
                ProductReplaceReasonCreate(title: $title, is_active: $is_active, position: $position) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'title' => 'Нет в наличии',
                'is_active' => true,
                'position' => 1,
            ]);

        $response->assertJson([
            'data' => [
                'ProductReplaceReasonCreate' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ],
        ]);

        $this->assertDatabaseHas('product_replace_reasons', [
            'title' => 'Нет в наличии',
            'is_active' => true,
            'position' => 1,
        ]);
    }

    /** @test */
    public function it_requires_title_on_create()
    {
        $mutation = '
            mutation ProductReplaceReasonCreate($title: String!) {
                ProductReplaceReasonCreate(title: $title) {
                    code
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'title' => '',
            ]);

        $response->assertJsonPath('errors.0.extensions.category', 'validation');
        $this->assertNotEmpty($response->json('errors.0.extensions.validation.title'));
    }

    /** @test */
    public function it_can_update_product_replace_reason()
    {
        $reason = $this->createReason(['title' => 'Старая причина']);

        $mutation = '
            mutation ProductReplaceReasonUpdate($id: Int!, $title: String, $is_active: Boolean, $position: Int) {
                ProductReplaceReasonUpdate(id: $id, title: $title, is_active: $is_active, position: $position) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $reason->id,
                'title' => 'Новая причина',
                'is_active' => false,
                'position' => 5,
            ]);

        $response->assertJson([
            'data' => [
                'ProductReplaceReasonUpdate' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ],
        ]);

        $this->assertDatabaseHas('product_replace_reasons', [
            'id' => $reason->id,
            'title' => 'Новая причина',
            'is_active' => false,
            'position' => 5,
        ]);
    }

    /** @test */
    public function it_can_delete_product_replace_reason()
    {
        $reason = $this->createReason();

        $mutation = '
            mutation ProductReplaceReasonDelete($id: Int!) {
                ProductReplaceReasonDelete(id: $id) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $reason->id,
            ]);

        $response->assertJson([
            'data' => [
                'ProductReplaceReasonDelete' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ],
        ]);

        $this->assertSoftDeleted('product_replace_reasons', [
            'id' => $reason->id,
        ]);
    }

    /** @test */
    public function it_can_query_active_product_replace_reasons_only()
    {
        $marker = uniqid('active_');
        $this->createReason(['title' => "Активная 1 {$marker}", 'is_active' => true]);
        $this->createReason(['title' => "Активная 2 {$marker}", 'is_active' => true]);
        $this->createReason(['title' => "Неактивная {$marker}", 'is_active' => false]);

        $query = '
            query {
                productReplaceReasons {
                    id
                    title
                    is_active
                }
            }
        ';

        $response = $this->actingAs($this->user)->graphQL($query);

        $reasons = collect($response->json('data.productReplaceReasons'));
        $ownReasons = $reasons->filter(fn ($r) => str_contains($r['title'], $marker));

        $this->assertCount(2, $ownReasons);
        $this->assertTrue($ownReasons->every(fn ($r) => $r['is_active'] === true));
    }

    /** @test */
    public function it_can_query_all_product_replace_reasons_including_inactive()
    {
        $marker = uniqid('all_');
        $this->createReason(['title' => "Активная {$marker}", 'is_active' => true]);
        $this->createReason(['title' => "Неактивная {$marker}", 'is_active' => false]);

        $query = '
            query {
                allProductReplaceReasons {
                    id
                    title
                    is_active
                }
            }
        ';

        $response = $this->actingAs($this->user)->graphQL($query);

        $reasons = collect($response->json('data.allProductReplaceReasons'));
        $ownReasons = $reasons->filter(fn ($r) => str_contains($r['title'], $marker));

        $this->assertCount(2, $ownReasons);
    }

    /** @test */
    public function it_can_query_single_product_replace_reason_by_id()
    {
        $reason = $this->createReason(['title' => 'Причина замены']);

        $query = '
            query ProductReplaceReason($id: Int!) {
                productReplaceReason(id: $id) {
                    id
                    title
                }
            }
        ';

        $response = $this->actingAs($this->user)->graphQL($query, ['id' => $reason->id]);

        $response->assertJson([
            'data' => [
                'productReplaceReason' => [
                    'id' => $reason->id,
                    'title' => 'Причина замены',
                ],
            ],
        ]);
    }
}
