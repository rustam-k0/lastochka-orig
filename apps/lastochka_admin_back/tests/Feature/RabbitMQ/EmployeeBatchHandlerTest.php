<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Role;
use App\Models\User;
use App\RabbitMQ\Handlers\EmployeeBatchHandler;
use App\Transformers\EmployeeBatchEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: пачка сотрудников из 1С эквивалентна N одиночным employee.updated.
 */
class EmployeeBatchHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['name' => Role::COLLECTOR, 'guard_name' => 'api'], ['name_ru' => 'Сборщик']);
        Role::updateOrCreate(['name' => Role::COURIER, 'guard_name' => 'api'], ['name_ru' => 'Курьер']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function item(string $surname, string $role = 'collector'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'surname' => $surname,
            'name' => 'Пачкин',
            'phone' => '+7999' . random_int(1000000, 9999999),
            'role' => $role,
        ];
    }

    private function envelope(array $items, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'batch_updated',
            'event_timestamp' => now()->toIso8601String(),
            'source' => '1c',
            'event_source' => '1c',
            'items' => $items,
        ], $overrides);
    }

    public function test_entity_is_consume_only(): void
    {
        $config = config('rabbitmq-events.entities.employee_batches');

        $this->assertFalse($config['enabled']);
        $this->assertSame(EmployeeBatchHandler::class, $config['handler']);
        $this->assertSame(['employee.batch_updated'], $config['routing_key_patterns']);
        $this->assertSame('admin_employee_batches_in', config('rabbitmq-events.inbound_queues.employee_batches'));
    }

    public function test_batch_creates_employees_and_skips_broken_items(): void
    {
        $this->setOneCShopsMaster(true);
        $first = $this->item('Первый');
        $second = $this->item('Второй', 'Курьер');
        $unknownRole = $this->item('Без роли', 'кладовщик');

        $result = app(EmployeeBatchHandler::class)->handle($this->envelope([
            $first,
            ['surname' => 'Без GUID'],
            'не объект',
            $unknownRole,
            $second,
        ]));

        $this->assertSame('ack', $result);
        $this->assertTrue(User::query()->where('phone', $first['phone'])->first()->hasRole(Role::COLLECTOR));
        $this->assertTrue(User::query()->where('phone', $second['phone'])->first()->hasRole(Role::COURIER));
        $this->assertFalse(User::query()->where('phone', $unknownRole['phone'])->exists());
    }

    public function test_repeated_batch_event_is_idempotent(): void
    {
        $this->setOneCShopsMaster(true);
        $item = $this->item('Повтор');
        $envelope = $this->envelope([$item]);

        app(EmployeeBatchHandler::class)->handle($envelope);
        $envelope['items'][0]['surname'] = 'Изменён повтором';
        app(EmployeeBatchHandler::class)->handle($envelope);

        $users = User::query()->where('phone', $item['phone'])->get();
        $this->assertCount(1, $users);
        $this->assertSame('Повтор', $users->first()->surname);
    }

    public function test_flag_off_or_not_1c_batch_is_skipped(): void
    {
        $this->setOneCShopsMaster(false);
        $offItem = $this->item('Выключен');
        $this->assertSame('ack', app(EmployeeBatchHandler::class)->handle($this->envelope([$offItem])));
        $this->assertFalse(User::query()->where('phone', $offItem['phone'])->exists());

        $this->setOneCShopsMaster(true);
        $foreignItem = $this->item('Чужой');
        app(EmployeeBatchHandler::class)->handle($this->envelope([$foreignItem], ['source' => 'retailbridge', 'event_source' => 'retailbridge']));
        $this->assertFalse(User::query()->where('phone', $foreignItem['phone'])->exists());
    }

    public function test_wrong_event_type_is_skipped_with_warning(): void
    {
        $this->setOneCShopsMaster(true);
        $item = $this->item('Не пачка');
        $envelope = $this->envelope([$item], ['event_type' => 'updated']);
        Log::spy();

        $this->assertSame('ack', app(EmployeeBatchHandler::class)->handle($envelope));

        $this->assertFalse(User::query()->where('phone', $item['phone'])->exists());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'event_type')
                && ($context['event_id'] ?? null) === $envelope['event_id']
                && ($context['event_type'] ?? null) === 'updated')
            ->once();
    }

    public function test_envelope_validation(): void
    {
        $transformer = app(EmployeeBatchEventTransformer::class);
        $envelope = $this->envelope([$this->item('Конверт')]);

        $this->assertTrue($transformer->validate($envelope));

        unset($envelope['items']);
        $this->assertFalse($transformer->validate($envelope));
    }

    public function test_consumer_routes_batch_to_handler(): void
    {
        $this->setOneCShopsMaster(true);
        $item = $this->item('Через консьюмер');

        $result = (new EventConsumer('employee_batches', config('rabbitmq-events.entities.employee_batches')))
            ->processEvent($this->envelope([$item]));

        $this->assertSame('ack', $result);
        $this->assertTrue(User::query()->where('phone', $item['phone'])->exists());
    }
}
