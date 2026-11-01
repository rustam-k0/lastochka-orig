<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class StoreForUserTypeScopeTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    private Store $b2bStore;
    private Store $b2cStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->b2bStore = Store::factory()->b2b()->create(['code' => 'B2B', 'name' => 'B2B-склад']);
        $this->b2cStore = Store::factory()->create(['code' => 'B2C', 'name' => 'B2C-магазин']);
    }

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    public function test_module_disabled_user_sees_all_stores(): void
    {
        $this->setB2bModule(false);

        $b2bUser = User::factory()->b2b()->create();

        $stores = Store::query()->forUserType($b2bUser)->get();

        $this->assertCount(2, $stores);
        $this->assertTrue($stores->contains('id', $this->b2bStore->id));
        $this->assertTrue($stores->contains('id', $this->b2cStore->id));
    }

    public function test_module_disabled_guest_sees_all_stores(): void
    {
        $this->setB2bModule(false);

        $stores = Store::query()->forUserType(null)->get();

        $this->assertCount(2, $stores);
    }

    public function test_module_enabled_guest_sees_only_b2c_stores(): void
    {
        $this->setB2bModule(true);

        $stores = Store::query()->forUserType(null)->get();

        $this->assertCount(1, $stores);
        $this->assertEquals($this->b2cStore->id, $stores->first()->id);
    }

    public function test_module_enabled_b2c_user_sees_only_b2c_stores(): void
    {
        $this->setB2bModule(true);

        $b2cUser = User::factory()->create(['is_b2b' => false]);

        $stores = Store::query()->forUserType($b2cUser)->get();

        $this->assertCount(1, $stores);
        $this->assertEquals($this->b2cStore->id, $stores->first()->id);
    }

    public function test_module_enabled_b2b_user_sees_all_stores_b2b_first(): void
    {
        $this->setB2bModule(true);

        $b2bUser = User::factory()->b2b()->create();

        $stores = Store::query()->forUserType($b2bUser)->get();

        $this->assertCount(2, $stores);
        $this->assertEquals(
            $this->b2bStore->id,
            $stores->first()->id,
            'B2B-магазин должен идти первым в выдаче для B2B-клиента'
        );
        $this->assertTrue($stores->contains('id', $this->b2cStore->id));
    }
}
