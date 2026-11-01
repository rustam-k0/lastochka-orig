<?php

declare(strict_types=1);

use App\Models\Product;
use App\RabbitMQ\Conditions\ProductSyncPublishCondition;

// Бутим Laravel-приложение (Model::$resolver), но БЕЗ RefreshDatabase: тесты
// работают только с in-memory моделью и не делают ни одного запроса к БД, поэтому
// сломанная под SQLite миграция 1c_parser нас не задевает.
uses(Tests\TestCase::class);

/**
 * Unit-тесты ProductSyncPublishCondition.
 *
 * Без БД (RefreshDatabase на 1c_parser ломается из-за MySQL-only миграции под
 * SQLite) — condition работает на in-memory состоянии модели:
 *  - wasRecentlyCreated различает created/updated,
 *  - wasChanged([...]) ловит реально изменённые поля.
 *
 * syncChanges() копирует текущий dirty (getDirty) в $changes, имитируя то, что
 * Eloquent делает в finishSave() после реального сохранения.
 */
beforeEach(function () {
    $this->condition = new ProductSyncPublishCondition();
});

test('создание товара — публикуем всегда', function () {
    $product = new Product(['name' => 'Любой товар']);
    $product->wasRecentlyCreated = true;

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился name — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Масло слив городень 82,5%',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->name = 'МАСЛО СЛИВ ГОРОДЕНЬ 82,5%';
    $product->syncChanges();

    expect($product->wasRecentlyCreated)->toBeFalse();
    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился только synced_at — НЕ публикуем (защита от лавины)', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар без изменений',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    // На каждом обмене updateOrCreate переписывает synced_at => now().
    $product->synced_at = '2026-06-09 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeFalse();
});

test('обновление: изменился barcode — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'barcode' => '0000000000001',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->barcode = '4810099032723';
    $product->synced_at = '2026-06-09 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился code — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'code' => '00041',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->code = '00042';
    $product->synced_at = '2026-06-09 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: code появился впервые (был null) — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'code' => null,
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    // Первый обмен после деплоя: у существующего товара появляется код.
    $product->code = '00042';
    $product->synced_at = '2026-06-09 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился category_id — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'category_id' => 10,
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->category_id = 25;
    $product->synced_at = '2026-06-09 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился is_active (деактивация) — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'is_active' => 1,
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->is_active = 0;
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился plu (появился впервые) — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Яблоки Голден',
        'plu' => null,
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->plu = '4126';
    $product->synced_at = '2026-07-01 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: изменился plu (было другое значение) — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Яблоки Голден',
        'plu' => '4126',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->plu = '4127';
    $product->synced_at = '2026-07-01 14:00:00';
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('мягко удалённый товар — публикуем (delete-событие)', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар на удаление',
        'deleted_at' => '2026-07-31 19:00:00',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('вызов из eloquent.deleting (deleted_at ещё пуст) — НЕ публикуем, защита от дубля', function () {
    // Пакет вешает слушатель и на deleting, и на deleted: без этой проверки
    // одно мягкое удаление дало бы два одинаковых события в outbox.
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар на удаление',
        'deleted_at' => null,
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->syncChanges();

    expect(($this->condition)($product))->toBeFalse();
});

test('восстановление товара (deleted_at снят) — публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'deleted_at' => '2026-07-31 19:00:00',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    $product->deleted_at = null;
    $product->syncChanges();

    expect(($this->condition)($product))->toBeTrue();
});

test('обновление: ничего значимого не менялось — НЕ публикуем', function () {
    $product = new Product();
    $product->setRawAttributes([
        'name' => 'Товар',
        'synced_at' => '2026-01-01 00:00:00',
    ], true);

    // Вообще без изменений — wasChanged пуст.
    $product->syncChanges();

    expect(($this->condition)($product))->toBeFalse();
});
