<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Console\Commands\RabbitMQ\ConsumeOrdersCommand;
use App\Console\Commands\RabbitMQ\SimulateOrderEventCommand;
use App\Console\Commands\RabbitMQ\SimulateOrderPaidCommand;
use App\Console\Commands\RabbitMQ\SimulateOrderStatusChangeCommand;
use App\Console\Commands\RabbitMQ\ProcessDeadLetterCommand;
use App\Console\Commands\RabbitMQ\PublishOutboxCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConsumeOrdersCommand::class,
        SimulateOrderEventCommand::class,
        SimulateOrderPaidCommand::class,
        SimulateOrderStatusChangeCommand::class,
        ProcessDeadLetterCommand::class,
        PublishOutboxCommand::class,
    ])
    ->withProviders()
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
