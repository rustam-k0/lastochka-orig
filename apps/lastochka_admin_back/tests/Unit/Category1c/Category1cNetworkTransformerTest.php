<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\Models\Category1c;
use App\Models\Shop;
use App\Services\Category1c\NetworkScopeConfigurator;
use App\Transformers\Category1cEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cNetworkTransformerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_from_event_uses_anchor_shop_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $attrs = (new Category1cEventTransformer())->fromEvent([
            'id' => 'guid-net-1',
            'shop_code' => $other->code,
            'title' => 'Молоко',
        ]);

        $this->assertSame($anchor->id, $attrs['shop_id']);
    }

    public function test_from_event_resolves_shop_code_when_network_mode_off(): void
    {
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $attrs = (new Category1cEventTransformer())->fromEvent([
            'id' => 'guid-net-2',
            'shop_code' => $other->code,
            'title' => 'Молоко',
        ]);

        $this->assertSame($other->id, $attrs['shop_id']);
    }

    public function test_find_existing_entity_matches_anchor_row_for_any_shop_code(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $existing = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-net-3',
        ]);

        $found = (new Category1cEventTransformer())->findExistingEntity(
            ['id' => 'guid-net-3', 'shop_code' => $other->code],
            []
        );

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_find_existing_entity_is_shop_scoped_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-net-4',
        ]);

        $found = (new Category1cEventTransformer())->findExistingEntity(
            ['id' => 'guid-net-4', 'shop_code' => $other->code],
            []
        );

        $this->assertNull($found);
    }

    public function test_configurator_enables_global_scope_only_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());

        $this->disableCategory1cNetworkMode();
        config(['rabbitmq-events.entities.categories_1c.global_scope' => false]);
        NetworkScopeConfigurator::apply();
        $this->assertFalse(config('rabbitmq-events.entities.categories_1c.global_scope'));

        $this->enableCategory1cNetworkMode($anchor->id);
        NetworkScopeConfigurator::apply();
        $this->assertTrue(config('rabbitmq-events.entities.categories_1c.global_scope'));
    }
}
