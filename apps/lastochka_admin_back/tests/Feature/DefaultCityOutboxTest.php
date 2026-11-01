<?php

namespace Tests\Feature;

use App\Models\City;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Смена города по умолчанию должна класть в outbox событие и для города,
 * с которого флаг сняли, иначе витрина покажет два города «по умолчанию».
 */
class DefaultCityOutboxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса observers регистрируются только в первом тесте процесса
        // и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();
    }

    private function makeCity(string $name, bool $isDefault = false): City
    {
        return City::create([
            'name' => $name . '-' . uniqid(),
            'is_active' => true,
            'is_default' => $isDefault,
        ]);
    }

    private function outboxCountFor(int $cityId): int
    {
        return DB::table('event_outbox')
            ->where('entity_type', 'cities')
            ->where('entity_id', $cityId)
            ->count();
    }

    public function test_reset_publishes_event_for_previous_default_city(): void
    {
        $old = $this->makeCity('Старый', true);
        $new = $this->makeCity('Новый');

        $before = $this->outboxCountFor($old->id);

        City::resetDefaultExcept($new->id);

        $this->assertFalse((bool) $old->fresh()->is_default);
        $this->assertGreaterThan(
            $before,
            $this->outboxCountFor($old->id),
            'снятие флага должно попасть в outbox, иначе витрина не узнает о смене'
        );
    }

    public function test_kept_city_is_not_touched(): void
    {
        $keep = $this->makeCity('Оставляем', true);

        City::resetDefaultExcept($keep->id);

        $this->assertTrue((bool) $keep->fresh()->is_default);
    }

    public function test_update_mutation_resets_previous_default(): void
    {
        $old = $this->makeCity('Прежний', true);
        $new = $this->makeCity('Следующий');

        $before = $this->outboxCountFor($old->id);

        (new \App\GraphQL\Mutations\City\CityUpdate())(null, [
            'id' => $new->id,
            'is_default' => true,
        ]);

        $this->assertTrue((bool) $new->fresh()->is_default);
        $this->assertFalse((bool) $old->fresh()->is_default);
        $this->assertGreaterThan($before, $this->outboxCountFor($old->id));
    }

    public function test_create_mutation_resets_previous_default(): void
    {
        $old = $this->makeCity('Был основным', true);

        $before = $this->outboxCountFor($old->id);

        (new \App\GraphQL\Mutations\City\CityCreate())(null, [
            'name' => 'Созданный-' . uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->assertFalse((bool) $old->fresh()->is_default);
        $this->assertGreaterThan($before, $this->outboxCountFor($old->id));
        $this->assertSame(1, City::where('is_default', true)->count());
    }
}
