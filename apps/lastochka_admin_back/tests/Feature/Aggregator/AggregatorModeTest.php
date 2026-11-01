<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Support\AggregatorMode;
use App\GraphQL\Mutations\AdminSetting\SaveProjectModules;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Тесты флага aggregator-режима (FOODLINK-10874, §6.2, §15.1).
 */
class AggregatorModeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(AggregatorMode::CACHE_KEY);
    }

    private function setFlag(bool $enabled): void
    {
        AdminSetting::setValue('project_modules', json_encode([
            'aggregator_enabled' => ['label' => 'X', 'enabled' => $enabled],
        ]), 'json');
    }

    public function test_disabled_by_default_when_no_setting(): void
    {
        AdminSetting::where('key', 'project_modules')->delete();
        Cache::forget(AggregatorMode::CACHE_KEY);

        $this->assertFalse((new AggregatorMode())->isEnabled());
    }

    public function test_reads_enabled_from_nested_module_key(): void
    {
        $this->setFlag(true);
        Cache::forget(AggregatorMode::CACHE_KEY);

        $this->assertTrue((new AggregatorMode())->isEnabled());
    }

    public function test_result_is_cached_until_flush(): void
    {
        $mode = new AggregatorMode();
        $this->setFlag(true);
        Cache::forget(AggregatorMode::CACHE_KEY);

        $this->assertTrue($mode->isEnabled());

        // Меняем БД, но кэш ещё держит true.
        $this->setFlag(false);
        $this->assertTrue($mode->isEnabled());

        // После сброса — читает актуальное значение.
        $mode->flushCache();
        $this->assertFalse($mode->isEnabled());
    }

    public function test_save_project_modules_accepts_aggregator_module_and_flushes_cache(): void
    {
        $mode = new AggregatorMode();
        $this->setFlag(false);
        Cache::forget(AggregatorMode::CACHE_KEY);
        $this->assertFalse($mode->isEnabled()); // прогреваем кэш

        $config = json_encode([
            'aggregator_enabled' => ['label' => 'Режим агрегатора', 'enabled' => true],
        ]);
        (new SaveProjectModules())(null, ['config' => $config]);

        // SaveProjectModules сбросил кэш → читается актуальное (true).
        $this->assertTrue($mode->isEnabled());
    }

    public function test_validation_accepts_aggregator_boolean_module(): void
    {
        $this->expectNotToPerformAssertions();
        (new SaveProjectModules())->validate([
            'aggregator_enabled' => ['label' => 'X', 'enabled' => true],
        ]);
    }
}
