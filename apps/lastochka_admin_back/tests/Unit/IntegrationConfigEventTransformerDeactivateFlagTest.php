<?php

namespace Tests\Unit;

use App\Models\IntegrationConfig;
use App\Transformers\IntegrationConfigEventTransformer;
use Tests\TestCase;

/**
 * Флаг «Удалять товары, не попавшие в полную выгрузку 1С» должен уезжать в
 * 1c_parser событием integration_config.*: парсер по нему решает, писать ли
 * состав выгрузки и выполнять ли команду deactivate от 1С.
 */
class IntegrationConfigEventTransformerDeactivateFlagTest extends TestCase
{
    private function makeConfig(array $attributes = []): IntegrationConfig
    {
        $config = new IntegrationConfig(array_merge([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => ['commerceml' => ['login' => '1c']],
            'is_active' => true,
        ], $attributes));
        $config->setRelation('shop', null);

        return $config;
    }

    public function test_event_carries_enabled_flag(): void
    {
        $event = (new IntegrationConfigEventTransformer())->toEvent(
            $this->makeConfig(['deactivate_missing_enabled' => true])
        );

        $this->assertTrue($event['deactivate_missing_enabled']);
    }

    public function test_event_carries_false_when_flag_not_set(): void
    {
        $event = (new IntegrationConfigEventTransformer())->toEvent($this->makeConfig());

        $this->assertArrayHasKey('deactivate_missing_enabled', $event);
        $this->assertFalse($event['deactivate_missing_enabled']);
    }
}
