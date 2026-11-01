<?php

namespace Tests\Feature\Integrations;

use App\GraphQL\Mutations\IntegrationConfig\SaveIntegrationConfig;
use App\GraphQL\Queries\IntegrationConfig\IntegrationConfigQueries;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Чекбокс «Удалять товары, не попавшие в полную выгрузку 1С» в форме
 * 1С-интеграции: мутация сохраняет флаг, запрос возвращает его, а отсутствие
 * аргумента не сбрасывает сохранённое значение (как у sync_images_enabled).
 */
class SaveIntegrationConfigDeactivateFlagTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        // Нужен пользователь с глобальным доступом (роль admin): мутация проверяет
        // доступ к магазину.
        $admin = User::role(Role::ADMIN)->firstOrFail();
        $this->actingAs($admin);
        $this->shopId = (int) Shop::query()->value('id');
        IntegrationConfig::where('integration_type', '1c')->where('shop_id', $this->shopId)->delete();
    }

    private function save(array $extra = []): array
    {
        return (new SaveIntegrationConfig())(null, array_merge([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'credentials' => json_encode(['login' => '1c_exchange', 'password' => 'secret']),
            'is_active' => true,
            'shop_id' => $this->shopId,
        ], $extra));
    }

    public function test_mutation_saves_flag_and_returns_it(): void
    {
        $result = $this->save(['deactivate_missing_enabled' => true]);

        $this->assertTrue($result['deactivate_missing_enabled']);
        $this->assertDatabaseHas('integration_configs', [
            'integration_type' => '1c',
            'shop_id' => $this->shopId,
            'deactivate_missing_enabled' => 1,
        ]);
    }

    public function test_flag_defaults_to_false(): void
    {
        $result = $this->save();

        $this->assertFalse($result['deactivate_missing_enabled']);
    }

    public function test_missing_argument_keeps_saved_value(): void
    {
        $this->save(['deactivate_missing_enabled' => true]);

        $result = $this->save();

        $this->assertTrue($result['deactivate_missing_enabled']);
    }

    public function test_query_returns_flag(): void
    {
        $this->save(['deactivate_missing_enabled' => true]);

        $config = (new IntegrationConfigQueries())->config(null, ['type' => '1c', 'shop_id' => $this->shopId]);

        $this->assertTrue($config['deactivate_missing_enabled']);
    }
}
