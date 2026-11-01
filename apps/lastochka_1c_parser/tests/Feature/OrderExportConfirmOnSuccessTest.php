<?php

use App\Models\Order;
use App\Models\OrderSyncLog;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Exchange1C\OrderExportHandler;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Заказ считается выгруженным в 1С только после mode=success.
 *
 * Цикл обмена 1С: checkauth → init → query → (обработка) → query (пока не пусто) → success.
 * Каждый шаг — отдельный HTTP-запрос, mavsan создаёт новый инстанс хендлера, сессия
 * обмена опознаётся по cookie из checkauth. 12.09.2026 1С падала на загрузке и не слала
 * success, а заказы уже были помечены на query — 14 заказов не дошли до 1С.
 */

function exchangeSession(?string $sessionId = null): string
{
    $sessionId ??= Str::random(40);
    request()->headers->set('cookie', config('session.cookie') . '=' . $sessionId . '; path=/');

    return $sessionId;
}

function withoutExchangeSession(): void
{
    request()->headers->remove('cookie');
    request()->headers->remove(config('session.cookie'));
}

function assembledOrder(array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'status' => Order::STATUS_ASSEMBLED,
        'shop_id' => 1,
        'exported_at' => null,
    ], $attrs));
}

function exportedNumbers(string $xml): array
{
    preg_match_all('~<Номер>([^<]*)</Номер>~u', $xml, $m);

    return $m[1];
}

it('query не помечает заказ выгруженным, success в той же сессии помечает', function () {
    $order = assembledOrder();
    exchangeSession();

    $xml = (new OrderExportHandler())->getXML();

    expect(exportedNumbers($xml))->toBe([(string) $order->number])
        ->and($order->fresh()->exported_at)->toBeNull();

    (new OrderExportHandler())->stepSuccess();

    $fresh = $order->fresh();
    expect($fresh->exported_at)->not->toBeNull()
        ->and($fresh->export_session_id)->toBeNull();
});

it('повторный query в той же сессии не отдаёт уже выданные заказы', function () {
    $order = assembledOrder();
    exchangeSession();

    expect(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([(string) $order->number]);
    expect(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([]);
});

it('в повторном query той же сессии уходит только заказ, появившийся после первого', function () {
    $first = assembledOrder();
    exchangeSession();

    (new OrderExportHandler())->getXML();
    $second = assembledOrder();

    expect(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([(string) $second->number]);

    (new OrderExportHandler())->stepSuccess();

    expect($first->fresh()->exported_at)->not->toBeNull()
        ->and($second->fresh()->exported_at)->not->toBeNull();
});

it('если 1С не прислала success, заказ уходит повторно в следующей сессии', function () {
    $order = assembledOrder();

    exchangeSession();
    (new OrderExportHandler())->getXML();

    exchangeSession();
    $xml = (new OrderExportHandler())->getXML();

    expect(exportedNumbers($xml))->toBe([(string) $order->number])
        ->and($order->fresh()->exported_at)->toBeNull();
});

it('success чужой сессии не подтверждает выданные в другой сессии заказы', function () {
    $order = assembledOrder();

    exchangeSession();
    (new OrderExportHandler())->getXML();

    exchangeSession();
    (new OrderExportHandler())->stepSuccess();

    expect($order->fresh()->exported_at)->toBeNull();
});

it('success пишет в журнал синхронизации статус success по выданным заказам', function () {
    $order = assembledOrder(['shop_order_id' => 321]);
    exchangeSession();

    (new OrderExportHandler())->getXML();
    expect(OrderSyncLog::where('order_id', $order->id)->pluck('status')->all())->toBe(['sent']);

    (new OrderExportHandler())->stepSuccess();
    expect(OrderSyncLog::where('order_id', $order->id)->pluck('status')->all())->toBe(['sent', 'success']);
});

it('смена статуса между query и success не даёт подтвердить устаревшую версию', function () {
    $order = assembledOrder(['shop_order_id' => 555]);
    exchangeSession();

    (new OrderExportHandler())->getXML();
    (new OrderImportService())->updateStatus(555, 'delivered');

    expect($order->fresh()->export_session_id)->toBeNull();

    // 1С в той же сессии повторяет query и получает новую версию заказа
    expect(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([(string) $order->number]);

    (new OrderExportHandler())->stepSuccess();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_DELIVERED)
        ->and($fresh->exported_at)->not->toBeNull();
});

it('смена состава между query и success сбрасывает выдачу', function () {
    $order = assembledOrder(['shop_order_id' => 556]);
    exchangeSession();

    (new OrderExportHandler())->getXML();
    (new OrderImportService())->syncItems($order->fresh(), [
        ['external_id' => 'ext-1', 'name' => 'Хлеб', 'quantity' => 1, 'price' => 50, 'line_total' => 50],
    ]);

    (new OrderExportHandler())->stepSuccess();

    expect($order->fresh()->exported_at)->toBeNull();
});

it('смена статуса из RabbitMQ (beforeUpdate) сбрасывает выдачу', function () {
    $order = assembledOrder();
    $order->forceFill(['export_session_id' => Str::random(40)])->save();

    $changes = (new OrderEventTransformer())->beforeUpdate($order, ['payload' => ['status' => 'delivered']], []);

    expect($changes)->toMatchArray([
        'status' => Order::STATUS_DELIVERED,
        'exported_at' => null,
        'export_session_id' => null,
    ]);
});

it('без cookie сессии обмена помечает заказ на query, чтобы 1С не зациклилась', function () {
    $order = assembledOrder();
    withoutExchangeSession();

    expect(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([(string) $order->number]);

    expect($order->fresh()->exported_at)->not->toBeNull()
        ->and(exportedNumbers((new OrderExportHandler())->getXML()))->toBe([]);
});

it('без cookie сессии ошибка сборки XML снимает пометку exported_at', function () {
    $order = assembledOrder();
    withoutExchangeSession();

    $broken = new class extends OrderExportHandler {
        protected function buildOrdersXml($orders, string $encoding = 'UTF-8'): string
        {
            throw new RuntimeException('boom');
        }
    };

    expect(exportedNumbers($broken->getXML()))->toBe([])
        ->and($order->fresh()->exported_at)->toBeNull();
});

it('ошибка сборки XML не оставляет заказ выданным сессии', function () {
    $order = assembledOrder();
    exchangeSession();

    $broken = new class extends OrderExportHandler {
        protected function buildOrdersXml($orders, string $encoding = 'UTF-8'): string
        {
            throw new RuntimeException('boom');
        }
    };

    expect(exportedNumbers($broken->getXML()))->toBe([]);

    (new OrderExportHandler())->stepSuccess();

    $fresh = $order->fresh();
    expect($fresh->exported_at)->toBeNull()
        ->and($fresh->export_session_id)->toBeNull();
});
