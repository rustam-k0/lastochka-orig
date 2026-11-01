<?php

namespace Tests\Feature;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Модуль «Оформление доставки без слотов» (checkout_without_slots, MID-256) в
 * дефолтах project_modules. Сам функционал живёт на витрине (storefront_back:
 * deliverySlotId опционален) и в admin_front (скрытие слотов) — здесь только
 * тумблер для «Настройки, Модули». Default OFF: на других форках поведение
 * не меняется.
 */
class CheckoutWithoutSlotsModuleDefaultTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function checkout_without_slots_module_present_in_defaults_and_disabled()
    {
        AdminSetting::where('key', 'project_modules')->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('checkout_without_slots', $decoded);
        $this->assertFalse($decoded['checkout_without_slots']['enabled']);
        $this->assertSame('Оформление доставки без слотов', $decoded['checkout_without_slots']['label']);
    }

    /** @test */
    public function checkout_without_slots_merged_into_stored_blob()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('checkout_without_slots', $decoded);
        $this->assertFalse($decoded['checkout_without_slots']['enabled']);
    }
}
