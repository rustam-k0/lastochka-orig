<?php

use App\Models\Order;
use App\Models\OrderSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('создаёт запись с attempt_number = 1 для первой попытки и копирует shop_order_id', function () {
    $order = Order::factory()->create(['shop_order_id' => 777]);

    $log = OrderSyncLog::createForOrder($order->id, OrderSyncLog::STATUS_SENT);

    expect($log->order_id)->toBe($order->id)
        ->and($log->shop_order_id)->toBe(777)
        ->and($log->status)->toBe('sent')
        ->and($log->attempt_number)->toBe(1)
        ->and($log->message)->toBeNull();
});

it('инкрементит attempt_number по order_id', function () {
    $order = Order::factory()->create(['shop_order_id' => 100]);

    OrderSyncLog::createForOrder($order->id, OrderSyncLog::STATUS_SENT);
    $second = OrderSyncLog::createForOrder($order->id, OrderSyncLog::STATUS_ERROR, 'boom');

    expect($second->attempt_number)->toBe(2)
        ->and($second->message)->toBe('boom');
});

it('pendingRetry создаёт запись со статусом pending_retry', function () {
    $order = Order::factory()->create(['shop_order_id' => 55]);

    $log = OrderSyncLog::pendingRetry($order->id);

    expect($log->status)->toBe('pending_retry')
        ->and($log->shop_order_id)->toBe(55);
});
