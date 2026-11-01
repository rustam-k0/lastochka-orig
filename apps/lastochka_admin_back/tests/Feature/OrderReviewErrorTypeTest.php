<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use App\Transformers\OrderReviewEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тип ошибки сборки у отзыва на заказ (недовоз, пересорт, просрочка, другое):
 * проставляет оператор, отчёт «Качество сборки» относит ошибку на сборщика.
 */
class OrderReviewErrorTypeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const SET = '
        mutation ($id: Int!, $error_type: String) {
            setOrderReviewErrorType(id: $id, error_type: $error_type) {
                id
                error_type
                error_type_label
            }
        }
    ';

    private Shop $shop;
    private OrderReview $review;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('own');
        $this->review = $this->makeReview($this->shop);
    }

    public function test_admin_sets_error_type(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(self::SET, ['id' => $this->review->id, 'error_type' => 'wrong_item'])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.setOrderReviewErrorType.error_type', 'wrong_item')
            ->assertJsonPath('data.setOrderReviewErrorType.error_type_label', 'Пересорт');

        $this->assertSame('wrong_item', $this->review->fresh()->error_type);
    }

    public function test_null_clears_error_type(): void
    {
        $this->review->update(['error_type' => 'shortage']);

        $this->actingAs($this->adminUser())
            ->graphQL(self::SET, ['id' => $this->review->id, 'error_type' => null])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.setOrderReviewErrorType.error_type', null)
            ->assertJsonPath('data.setOrderReviewErrorType.error_type_label', null);

        $this->assertNull($this->review->fresh()->error_type);
    }

    public function test_unknown_error_type_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->graphQL(self::SET, ['id' => $this->review->id, 'error_type' => 'courier_late']);

        $this->assertSame(
            ['Неизвестный тип ошибки'],
            $response->json('errors.0.extensions.validation.error_type')
        );
        $this->assertNull($this->review->fresh()->error_type);
    }

    public function test_shop_user_cannot_mark_foreign_shop_review(): void
    {
        $partner = $this->makeUser(Role::SHOP);
        UserShop::create(['user_id' => $partner->id, 'shop_id' => $this->makeShop('partner')->id]);

        $response = $this->actingAs($partner)
            ->graphQL(self::SET, ['id' => $this->review->id, 'error_type' => 'expired']);

        $this->assertNotNull($response->json('errors'));
        $this->assertNull($this->review->fresh()->error_type);
    }

    public function test_shop_user_marks_own_shop_review(): void
    {
        $partner = $this->makeUser(Role::SHOP);
        UserShop::create(['user_id' => $partner->id, 'shop_id' => $this->shop->id]);

        $this->actingAs($partner)
            ->graphQL(self::SET, ['id' => $this->review->id, 'error_type' => 'expired'])
            ->assertGraphQLErrorFree();

        $this->assertSame('expired', $this->review->fresh()->error_type);
    }

    public function test_list_filters_by_error_type_and_returns_label(): void
    {
        $other = $this->makeReview($this->shop);
        $this->review->update(['error_type' => 'shortage']);

        $response = $this->actingAs($this->adminUser())->graphQL('
            query ($error_type: String) {
                orderReviews(error_type: $error_type, first: 100) {
                    data { id error_type error_type_label }
                }
            }
        ', ['error_type' => 'shortage']);

        $response->assertGraphQLErrorFree();
        $ids = collect($response->json('data.orderReviews.data'))->pluck('id')->all();

        $this->assertContains($this->review->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame('Недовоз', collect($response->json('data.orderReviews.data'))
            ->firstWhere('id', $this->review->id)['error_type_label']);
    }

    public function test_error_types_dictionary(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL('{ orderReviewErrorTypes { value label } }')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.orderReviewErrorTypes', [
                ['value' => 'shortage', 'label' => 'Недовоз'],
                ['value' => 'wrong_item', 'label' => 'Пересорт'],
                ['value' => 'expired', 'label' => 'Просрочка'],
                ['value' => 'other', 'label' => 'Другое'],
            ]);
    }

    public function test_inbound_review_update_keeps_error_type(): void
    {
        // Отзыв правится в витрине и приходит order_review.updated: тип ошибки,
        // проставленный оператором, событие затирать не должно.
        $attributes = (new OrderReviewEventTransformer())->fromEvent([
            'event_id' => (string) Str::uuid(),
            'id' => '1',
            'source' => 'storefront',
            'rating' => 2,
            'description' => 'Привезли не то',
        ]);

        $this->assertArrayNotHasKey('error_type', $attributes);
    }

    private function makeShop(string $prefix): Shop
    {
        return Shop::create([
            'title' => 'ErrorType ' . $prefix . ' ' . uniqid(),
            'code' => $prefix . '_' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function makeReview(Shop $shop): OrderReview
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'ErrorType',
            'is_authorized' => true,
        ]);

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'shop_id' => $shop->id,
            'status_id' => 5,
            'type_id' => 2,
            'amount' => 100,
            'payment_type_id' => 1,
        ]);

        return OrderReview::create([
            'order_id' => $order->id,
            'rating' => 2,
            'description' => 'Не тот товар',
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => 'Partner',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('partner_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole(Role::findOrCreate($role));

        return $user;
    }
}
