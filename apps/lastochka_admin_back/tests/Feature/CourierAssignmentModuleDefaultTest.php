<?php

namespace Tests\Feature;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Модуль «Назначение курьера сборщиком» (courier_assignment) в дефолтах
 * project_modules (Platrum 12183 / MID-151). Сам функционал живёт в
 * delivery_back (читает admin_settings общей БД напрямую) — здесь только
 * тумблер для «Настройки, Модули».
 */
class CourierAssignmentModuleDefaultTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function courier_assignment_module_present_in_defaults_and_disabled()
    {
        AdminSetting::where('key', 'project_modules')->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('courier_assignment', $decoded);
        $this->assertFalse($decoded['courier_assignment']['enabled']);
        $this->assertSame('Назначение курьера сборщиком', $decoded['courier_assignment']['label']);
    }

    /** @test */
    public function courier_assignment_merged_into_stored_blob()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('courier_assignment', $decoded);
        $this->assertFalse($decoded['courier_assignment']['enabled']);
    }
}
