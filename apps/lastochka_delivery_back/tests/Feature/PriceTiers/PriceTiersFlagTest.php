<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceTiersFlagTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  public function test_disabled_when_project_modules_setting_is_missing(): void
  {
    \DB::table('admin_settings')->where('key', 'project_modules')->delete();

    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_disabled_when_module_is_absent_in_blob(): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode(['collector_payment' => ['enabled' => true]]), 'type' => 'json', 'updated_at' => now()]
    );

    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_enabled_from_project_modules(): void
  {
    $this->setPriceTiersFlag(true);

    $this->assertTrue(Order::isPriceTiersModuleEnabled());
  }

  public function test_flag_is_not_memoized_between_calls(): void
  {
    // Тесты и долгоживущие воркеры переключают флаг внутри одного PHP-процесса
    $this->setPriceTiersFlag(true);
    $this->assertTrue(Order::isPriceTiersModuleEnabled());

    $this->setPriceTiersFlag(false);
    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_broken_blob_means_disabled(): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => 'не json', 'type' => 'json', 'updated_at' => now()]
    );

    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_toggle_keeps_other_modules_of_the_blob(): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode(['collector_payment' => ['enabled' => true]]), 'type' => 'json', 'updated_at' => now()]
    );

    $this->setPriceTiersFlag(true);

    $this->assertTrue(Order::isCollectorPaymentModuleEnabled(), 'фикстура не должна затирать соседние модули');
    $this->assertTrue(Order::isPriceTiersModuleEnabled());
  }
}
