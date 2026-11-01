<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EffectiveStockPreviewTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
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
    }

    private const PREVIEW_QUERY = '
        query ($category_ids: [Int!], $shop_id: Int, $unit_type_id: Int) {
            effective_stock_preview(category_ids: $category_ids, shop_id: $shop_id, unit_type_id: $unit_type_id) {
                mode
                fixed
                fixed_unit_type_id
            }
        }
    ';

    /** @test */
    public function it_returns_fixed_mode_from_category()
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-gql-fixed',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 7,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->actingAs($this->adminUser)
            ->graphQL(self::PREVIEW_QUERY, [
                'category_ids' => [$cat->id],
                'unit_type_id' => Type::INFO_WEIGHT_PIECE,
            ])
            ->assertJson([
                'data' => [
                    'effective_stock_preview' => [
                        'mode' => 'fixed',
                        'fixed' => 7,
                        'fixed_unit_type_id' => Type::INFO_WEIGHT_PIECE,
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_returns_normal_when_no_categories_selected()
    {
        $this->actingAs($this->adminUser)
            ->graphQL(self::PREVIEW_QUERY, [
                'category_ids' => [],
            ])
            ->assertJson([
                'data' => [
                    'effective_stock_preview' => [
                        'mode' => 'normal',
                        'fixed' => null,
                        'fixed_unit_type_id' => null,
                    ],
                ],
            ]);
    }

    /**
     * Запрос без actingAs() должен быть отклонён `@guard` до всякой бизнес-логики
     * (LASTOCHKI-JAMI-11207-подобный паттерн из EntityFieldsTest::unauthenticated_request_is_rejected).
     * Lighthouse @guard формирует Nuwave\Lighthouse\Exceptions\AuthenticationException
     * с message = 'Unauthenticated.' (см. vendor/nuwave/lighthouse/src/Exceptions/AuthenticationException.php).
     *
     * @test
     */
    public function it_rejects_unauthenticated_request()
    {
        $this->graphQL(self::PREVIEW_QUERY, [
            'category_ids' => [],
        ])->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
