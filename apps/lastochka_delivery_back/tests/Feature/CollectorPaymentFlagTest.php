<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CollectorPaymentFlagTest extends TestCase
{
    use DatabaseTransactions;

    public function test_disabled_by_default(): void
    {
        \DB::table('admin_settings')->where('key', 'project_modules')->delete();
        $this->assertFalse(Order::isCollectorPaymentModuleEnabled());
    }

    public function test_enabled_from_project_modules(): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['collector_payment' => ['label' => 'Списание оплаты сборщиком', 'enabled' => true]]),
             'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
        $this->assertTrue(Order::isCollectorPaymentModuleEnabled());
    }
}
