<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Полный HTTP-цикл обмена заказами так, как его ведёт 1С Ласточки (прод, nginx 13.09.2026):
 * checkauth → init → query (заказы) → query (пусто) → success.
 * Каждый шаг — отдельный запрос через роут /1c-exchange/{shop_code} и контроллер mavsan.
 */

beforeEach(function () {
    makeIntegrationConfig([
        'shop_id' => 4,
        'shop_code' => 'djami_store',
        'credentials' => ['login' => '1c_exchange', 'password' => 'secret'],
    ]);
    User::factory()->create();
});

function exchangeRequest($test, string $mode, ?array $session = null)
{
    // Контроллер mavsan берёт Request в конструкторе, а Laravel кэширует его экземпляр
    // на роуте между запросами одного теста (на проде каждый запрос — новый процесс).
    app('router')->getRoutes()->getByName('1c-exchange-shop')->flushController();

    $query = ['type' => 'sale', 'mode' => $mode];
    $cookies = [];
    $server = ['PHP_AUTH_USER' => '1c_exchange', 'PHP_AUTH_PW' => 'secret'];

    if ($session) {
        $query[$session['token']] = '1';
        // 1С возвращает cookie из checkauth заголовком Cookie во всех запросах цикла
        $cookies[$session['name']] = $session['id'];
        $server['HTTP_COOKIE'] = $session['name'] . '=' . $session['id'];
    }

    // call() без default-заголовков/cookies: новый цикл 1С начинается с checkauth без cookie
    return $test->call('GET', '/1c-exchange/djami_store?' . http_build_query($query), [], $cookies, [], $server);
}

function openExchangeSession($test): array
{
    $lines = explode("\n", iconv('windows-1251', 'UTF-8', exchangeRequest($test, 'checkauth')->getContent()));
    expect($lines[0])->toBe('success');

    $session = ['name' => $lines[1], 'id' => $lines[2], 'token' => $lines[3]];
    exchangeRequest($test, 'init', $session)->assertOk();

    return $session;
}

function documentNumbers(string $body): array
{
    preg_match_all('~<Номер>([^<]*)</Номер>~u', $body, $m);

    return $m[1];
}

it('заказ помечается выгруженным только после success, повторный query в сессии пустой', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_ASSEMBLED, 'shop_id' => 4]);

    $session = openExchangeSession($this);

    expect(documentNumbers(exchangeRequest($this, 'query', $session)->getContent()))->toBe([(string) $order->number])
        ->and($order->fresh()->exported_at)->toBeNull();

    expect(documentNumbers(exchangeRequest($this, 'query', $session)->getContent()))->toBe([]);

    expect(exchangeRequest($this, 'success', $session)->getContent())->toBe('success')
        ->and($order->fresh()->exported_at)->not->toBeNull();
});

it('сессия, оборванная без success, не теряет заказ: он уходит в следующем цикле', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_ASSEMBLED, 'shop_id' => 4]);

    $failed = openExchangeSession($this);
    expect(documentNumbers(exchangeRequest($this, 'query', $failed)->getContent()))->toBe([(string) $order->number]);
    // 1С упала на загрузке («Не удалось заполнить поле "Склад"») и success не прислала

    $next = openExchangeSession($this);
    expect($next['id'])->not->toBe($failed['id']);

    expect(documentNumbers(exchangeRequest($this, 'query', $next)->getContent()))->toBe([(string) $order->number]);
    exchangeRequest($this, 'query', $next);
    exchangeRequest($this, 'success', $next);

    expect($order->fresh()->exported_at)->not->toBeNull();
});
