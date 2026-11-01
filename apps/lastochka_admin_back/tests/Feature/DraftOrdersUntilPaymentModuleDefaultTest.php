<?php

namespace Tests\Feature;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Модуль «Заказ только после оплаты» (draft_orders_until_payment) в дефолтах
 * project_modules. Функционал живёт на витрине (storefront_back: заказ при
 * онлайн-оплате новой картой создаётся черновиком и попадает в работу только
 * после подтверждения холда) — здесь только тумблер для «Настройки, Модули».
 * Default OFF: на других форках платёжный флоу не меняется.
 */
class DraftOrdersUntilPaymentModuleDefaultTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function draft_orders_until_payment_module_present_in_defaults_and_disabled()
    {
        AdminSetting::where('key', 'project_modules')->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('draft_orders_until_payment', $decoded);
        $this->assertFalse($decoded['draft_orders_until_payment']['enabled']);
        $this->assertSame('Заказ только после оплаты', $decoded['draft_orders_until_payment']['label']);
    }

    /** @test */
    public function draft_orders_until_payment_merged_into_stored_blob()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('draft_orders_until_payment', $decoded);
        $this->assertFalse($decoded['draft_orders_until_payment']['enabled']);
    }
}
