<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\Settings\ModuleConfigService;
use App\Support\HeavyOrderResolver;
use Mockery;
use Tests\TestCase;

class HeavyOrderResolverTest extends TestCase
{
    private function resolver(bool $moduleEnabled, bool $courierAssignmentEnabled = false): HeavyOrderResolver
    {
        $modules = Mockery::mock(ModuleConfigService::class);
        $modules->shouldReceive('isHeavyOrdersEnabled')->andReturn($moduleEnabled);
        $modules->shouldReceive('isCourierAssignmentEnabled')->andReturn($courierAssignmentEnabled);

        return new HeavyOrderResolver($modules);
    }

    private function store(?int $threshold): Store
    {
        return new Store(['heavy_order_threshold' => $threshold]);
    }

    public function test_false_when_module_disabled(): void
    {
        $this->assertFalse($this->resolver(false)->isHeavy($this->store(30000), 40000));
    }

    public function test_false_when_threshold_null_or_zero(): void
    {
        $this->assertFalse($this->resolver(true)->isHeavy($this->store(null), 40000));
        $this->assertFalse($this->resolver(true)->isHeavy($this->store(0), 40000));
    }

    public function test_false_when_weight_unknown(): void
    {
        $this->assertFalse($this->resolver(true)->isHeavy($this->store(30000), null));
    }

    public function test_false_when_weight_equal_to_threshold(): void
    {
        // «Превышает» — строго больше
        $this->assertFalse($this->resolver(true)->isHeavy($this->store(30000), 30000));
    }

    public function test_true_when_weight_exceeds_threshold(): void
    {
        $this->assertTrue($this->resolver(true)->isHeavy($this->store(30000), 30001));
    }

    public function test_false_when_store_null(): void
    {
        $this->assertFalse($this->resolver(true)->isHeavy(null, 40000));
    }

    public function test_true_under_courier_assignment_module_only(): void
    {
        // Platrum 12180: признак «Тяжёлый заказ» активен и при модуле
        // courier_assignment (heavy_orders выключен)
        $this->assertTrue($this->resolver(false, true)->isHeavy($this->store(30000), 30001));
    }

    public function test_false_when_both_modules_disabled(): void
    {
        $this->assertFalse($this->resolver(false, false)->isHeavy($this->store(30000), 30001));
    }
}
