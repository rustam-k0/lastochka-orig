<?php

use App\Console\Commands\ConsumeBonusOperationsCommand;
use App\Console\Commands\ConsumeCustomersCommand;
use App\Console\Commands\ConsumeLoyaltyCardsCommand;
use App\Console\Commands\ConsumeOrdersCommand;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

if (! function_exists('consumerCmdSetProp')) {
    function consumerCmdSetProp(object $obj, string $prop, mixed $value): void
    {
        $class = $obj instanceof Mockery\MockInterface ? get_parent_class($obj) : get_class($obj);
        $ref = new ReflectionProperty($class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}

if (! function_exists('consumerCmdInvoke')) {
    function consumerCmdInvoke(object $obj, string $method, array $args = []): mixed
    {
        $class = $obj instanceof Mockery\MockInterface ? get_parent_class($obj) : get_class($obj);
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }
}

dataset('consumerCommands', [
    'bonus-operations' => [ConsumeBonusOperationsCommand::class],
    'customers' => [ConsumeCustomersCommand::class],
    'orders' => [ConsumeOrdersCommand::class],
    'loyalty-cards' => [ConsumeLoyaltyCardsCommand::class],
]);

it('ждёт сообщения блокирующе: wait получает int 0, а не null, при --timeout=0', function (string $commandClass) {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_consume')->once()->andReturn('ctag');
    $channel->shouldReceive('is_consuming')->twice()->andReturn(true, false);
    // строгая проверка === 0: null означает non-blocking poll (busy-spin), 0 — блокирующее ожидание
    $channel->shouldReceive('wait')
        ->once()
        ->with(null, false, Mockery::on(fn ($timeout) => $timeout === 0))
        ->andReturnNull();

    $command = Mockery::mock($commandClass)->makePartial();
    $command->shouldReceive('option')->with('timeout')->andReturn('0');
    $command->shouldReceive('option')->with('once')->andReturn(false);

    consumerCmdSetProp($command, 'channel', $channel);
    consumerCmdSetProp($command, 'maxMessages', 100000);
    consumerCmdSetProp($command, 'memoryLimit', 4096);

    consumerCmdInvoke($command, 'consume', [[
        'queue' => 'test_queue',
        'retry' => ['max_attempts' => 3],
    ]]);

    expect(true)->toBeTrue(); // ожидания Mockery проверяются при tearDown
})->with('consumerCommands');

it('requeue переотправляет копию с x-retry-count и ack-ает оригинал', function (string $commandClass) {
    $channel = Mockery::mock(AMQPChannel::class);

    $published = null;
    $channel->shouldReceive('basic_publish')
        ->once()
        ->with(Mockery::capture($published), '', 'test_queue');
    $channel->shouldReceive('basic_ack')->once()->with(7, false);

    $command = new $commandClass();
    consumerCmdSetProp($command, 'channel', $channel);

    $original = new AMQPMessage('{"id":123}', [
        'content_type' => 'application/json',
        'delivery_mode' => 2,
    ]);
    $original->setChannel($channel);
    $original->setDeliveryTag(7);

    consumerCmdInvoke($command, 'requeue', [$original, 2, 'test_queue']);

    expect($published)->toBeInstanceOf(AMQPMessage::class);
    expect($published->getBody())->toBe('{"id":123}');
    expect($published->get_properties()['content_type'] ?? null)->toBe('application/json');

    // round-trip: следующая доставка должна прочитать инкрементированный счётчик
    expect(consumerCmdInvoke($command, 'getRetryCount', [$published]))->toBe(2);
})->with('consumerCommands');
