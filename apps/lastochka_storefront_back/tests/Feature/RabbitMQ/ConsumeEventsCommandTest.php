<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Console\Commands\ConsumeEventsCommand;
use App\RabbitMQ\Debug\LoggingEventConsumer;
use Comrades\RabbitMQEvents\Commands\ConsumeEvents;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * rabbitmq:consume витрины = команда пакета (несколько очередей одним
 * процессом для rabbitmq:consume-all, адресные ретраи, dead letters), а
 * потребитель с debug-логом и orphan-recover подставляется хуком makeConsumer().
 */
final class ConsumeEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_command_replaces_package_command_and_accepts_several_entities(): void
    {
        $command = $this->app[Kernel::class]->all()['rabbitmq:consume'];

        $this->assertInstanceOf(ConsumeEventsCommand::class, $command);
        $this->assertInstanceOf(ConsumeEvents::class, $command);
        $this->assertTrue(
            $command->getDefinition()->getArgument('entity')->isArray(),
            'consume-all отдаёт общему процессу несколько сущностей; со старой сигнатурой {entity} он откатывается на процесс на каждую очередь'
        );
        $this->assertTrue($command->getDefinition()->hasOption('queue'));
    }

    public function test_consumer_of_each_entity_is_logging_consumer_bound_to_its_queue(): void
    {
        $command = new ConsumeEventsCommand();
        $command->setLaravel($this->app);

        $consumer = (new \ReflectionMethod($command, 'makeConsumer'))
            ->invoke($command, 'shops', config('rabbitmq-events.entities.shops'), 'storefront_shops');

        $this->assertInstanceOf(LoggingEventConsumer::class, $consumer);
        $this->assertInstanceOf(EventConsumer::class, $consumer, 'потребитель пригоден для handleMessage() пакета');
        $this->assertSame('storefront_shops', (new \ReflectionProperty($consumer, 'queueName'))->getValue($consumer));
    }

    public function test_logging_consumer_logs_routing_key_of_the_event_when_called_by_package(): void
    {
        config(['rabbit-debug.enabled' => true, 'rabbit-debug.async_logging' => false]);
        $consumer = new LoggingEventConsumer('shops', config('rabbitmq-events.entities.shops'), 'storefront_shops');

        // Так зовёт ConsumeEvents::handleMessage(): один аргумент, ключ события в _routing_key
        // (у адресного ретрая это x-original-routing-key, а не имя очереди из доставки).
        $result = $consumer->processEvent([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_rk',
            'source' => 'bagisto',
            'shop_code' => 'RK_SHOP',
            'title' => 'Routing key shop',
            'latitude' => 55.75,
            'longitude' => 37.62,
            'active' => true,
            '_routing_key' => 'shop.status_changed',
        ]);

        $this->assertSame('ack', $result);
        $this->assertDatabaseHas('rabbit_message_log', [
            'direction' => 'inbound',
            'routing_key' => 'shop.status_changed',
            'queue_name' => 'storefront_shops',
            'external_id' => 'ext_shop_rk',
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('stores', ['code' => 'RK_SHOP', 'name' => 'Routing key shop']);
    }
}
