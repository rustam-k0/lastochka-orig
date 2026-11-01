<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль «Оформление заказа без слотов» (checkout_without_slots, MID-256):
 * гейт-запрос для приложений сборщика/курьера — при включённом модуле слот
 * заказа не показывается (заказы уходят «на ближайшее время», в djami слот
 * = момент оформления по fallback консьюмера).
 */
class CheckoutWithoutSlotsModuleTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['checkout_without_slots' => ['enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test */
    public function flag_query_returns_false_by_default()
    {
        \DB::table('admin_settings')->where('key', 'project_modules')->delete();

        $this->graphQL('{ checkout_without_slots_enabled }')
            ->assertJson(['data' => ['checkout_without_slots_enabled' => false]]);
        $this->assertFalse(Order::isCheckoutWithoutSlotsModuleEnabled());
    }

    /** @test */
    public function flag_query_returns_true_when_module_enabled()
    {
        $this->setModule(true);

        $this->graphQL('{ checkout_without_slots_enabled }')
            ->assertJson(['data' => ['checkout_without_slots_enabled' => true]]);
        $this->assertTrue(Order::isCheckoutWithoutSlotsModuleEnabled());
    }

    /** @test */
    public function flag_query_returns_false_when_module_disabled()
    {
        $this->setModule(false);

        $this->graphQL('{ checkout_without_slots_enabled }')
            ->assertJson(['data' => ['checkout_without_slots_enabled' => false]]);
    }
}
