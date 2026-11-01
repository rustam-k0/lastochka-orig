<?php

use App\Models\Order;
use App\Services\Exchange1C\OrderStatusSyncService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new OrderStatusSyncService();
    $this->determineStatus = new ReflectionMethod($this->service, 'determineStatus');
});

test('determineStatus возвращает статус из реквизита «Статус заказа» если он валидный', function (string $status) {
    $document = makeDocumentWithStatus($status);

    expect($this->determineStatus->invoke($this->service, $document))->toBe($status);
})->with([
    'Новый',
    'НаСборке',
    'Собран',
    'ВДоставке',
    'Доставлен',
    'Отменен',
]);

test('determineStatus возвращает null и логирует warning для неизвестного статуса', function () {
    $document = makeDocumentWithStatus('Возврат');

    expect($this->determineStatus->invoke($this->service, $document))->toBeNull();
});

test('determineStatus возвращает null для пустого значения «Статус заказа»', function () {
    $document = makeDocumentWithStatus('');

    expect($this->determineStatus->invoke($this->service, $document))->toBeNull();
});

test('determineStatus fallback: Отменен=true без реквизита «Статус заказа»', function () {
    $document = [
        'Ид' => 'order-1',
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Отменен', 'Значение' => 'true'],
                ['Наименование' => 'Проведен', 'Значение' => 'true'],
            ],
        ],
    ];

    expect($this->determineStatus->invoke($this->service, $document))->toBe(Order::STATUS_CANCELLED);
});

test('determineStatus fallback: ПометкаУдаления=true без реквизита «Статус заказа»', function () {
    $document = [
        'Ид' => 'order-1',
        'ПометкаУдаления' => 'true',
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Проведен', 'Значение' => 'true'],
            ],
        ],
    ];

    expect($this->determineStatus->invoke($this->service, $document))->toBe(Order::STATUS_CANCELLED);
});

test('determineStatus возвращает null если нет «Статус заказа» и Проведен=true', function () {
    $document = [
        'Ид' => 'order-1',
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Проведен', 'Значение' => 'true'],
                ['Наименование' => 'Отменен', 'Значение' => 'false'],
            ],
        ],
    ];

    expect($this->determineStatus->invoke($this->service, $document))->toBeNull();
});

test('determineStatus возвращает null если нет реквизитов вообще', function () {
    $document = ['Ид' => 'order-1'];

    expect($this->determineStatus->invoke($this->service, $document))->toBeNull();
});

test('determineStatus: реквизит «Статус заказа» приоритетнее «Отменен»', function () {
    $document = [
        'Ид' => 'order-1',
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Статус заказа', 'Значение' => 'НаСборке'],
                ['Наименование' => 'Отменен', 'Значение' => 'true'],
            ],
        ],
    ];

    expect($this->determineStatus->invoke($this->service, $document))->toBe('НаСборке');
});

test('processDocument пропускает заказ «Лояльность»: статус не меняется, событие не публикуется', function () {
    $order = makeOrder([
        'source' => Order::SOURCE_LOYALTY_CARD,
        'status' => Order::STATUS_ASSEMBLED,
    ]);

    $document = [
        'Ид' => 'order-' . $order->id,
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Статус заказа', 'Значение' => Order::STATUS_IN_DELIVERY],
            ],
        ],
    ];

    $processDocument = new ReflectionMethod($this->service, 'processDocument');
    $processDocument->setAccessible(true);

    expect($processDocument->invoke($this->service, $document))->toBeFalse()
        ->and($order->refresh()->status)->toBe(Order::STATUS_ASSEMBLED)
        ->and(EventOutbox::count())->toBe(0);
});

function makeDocumentWithStatus(string $status): array
{
    return [
        'Ид' => 'order-1',
        'ЗначенияРеквизитов' => [
            'ЗначениеРеквизита' => [
                ['Наименование' => 'Статус заказа', 'Значение' => $status],
                ['Наименование' => 'Проведен', 'Значение' => 'true'],
            ],
        ],
    ];
}
