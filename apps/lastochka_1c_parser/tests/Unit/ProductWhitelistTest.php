<?php

use App\Services\Exchange1C\ProductWhitelist;
use Tests\TestCase;

uses(TestCase::class);

// С multishop-рефакторинга (12c5185) конструктор принимает путь к CSV (null = whitelist выключен)
// и больше не читает config. Настройки exchange1c.whitelist_* читает фабрика fromShopConfig():
// вне HTTP-контекста магазина (current_shop_config не забинден) она берёт глобальный конфиг.

beforeEach(function () {
    // Создаём временный CSV файл для тестов
    $this->tempCsvPath = sys_get_temp_dir() . '/test_whitelist_' . uniqid() . '.csv';
});

afterEach(function () {
    // Удаляем временный файл
    if (isset($this->tempCsvPath) && file_exists($this->tempCsvPath)) {
        unlink($this->tempCsvPath);
    }
});

test('loads whitelist from csv file with barcodes', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    $csv .= "2,Бананы кг,-,\"140,0\",кг,2200647\n";
    $csv .= "3,Картофель,-,\"42,0\",кг,2200574\n";
    file_put_contents($this->tempCsvPath, $csv);

    config()->set('exchange1c.whitelist_enabled', true);
    config()->set('exchange1c.whitelist_path', $this->tempCsvPath);

    $whitelist = ProductWhitelist::fromShopConfig();
    $whitelist->load();

    expect($whitelist->getCount())->toBe(3);
    
    // PHP конвертирует числовые строки в int ключи, поэтому сравниваем нестрого
    $barcodes = array_map('strval', $whitelist->getBarcodes());
    expect($barcodes)->toContain('2200569', '2200647', '2200574');
});

test('allows exact barcode match', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();

    expect($whitelist->isAllowed(['2200569']))->toBeTrue();
    expect($whitelist->isAllowed(['  2200569  ']))->toBeTrue(); // trim
});

test('allows product with multiple barcodes when one matches', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();

    // Товар с несколькими штрихкодами, один из них в whitelist
    expect($whitelist->isAllowed(['9999999', '2200569', '1111111']))->toBeTrue();
});

test('denies products with barcodes not in whitelist', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();

    expect($whitelist->isAllowed(['9999999']))->toBeFalse();
    expect($whitelist->isAllowed(['1111111', '2222222']))->toBeFalse();
});

test('denies products with empty barcodes array', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();

    // Товар без штрихкода не должен импортироваться
    expect($whitelist->isAllowed([]))->toBeFalse();
});

test('allows all products when whitelist disabled', function () {
    config(['exchange1c.whitelist_enabled' => false]);

    $whitelist = ProductWhitelist::fromShopConfig();

    expect($whitelist->isAllowed(['9999999']))->toBeTrue();
    expect($whitelist->isAllowed([]))->toBeTrue();
});

test('throws exception when csv file not found', function () {
    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => '/nonexistent/path/list.csv']);

    $whitelist = ProductWhitelist::fromShopConfig();

    expect(fn() => $whitelist->load())->toThrow(RuntimeException::class);
});

test('handles empty csv file', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n"; // Only header
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $whitelist->load();

    expect($whitelist->getCount())->toBe(0);
    expect($whitelist->isAllowed(['2200569']))->toBeFalse();
});

test('skips rows with less than 6 columns', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    $csv .= "2,Бананы,-,\"140,0\",кг\n"; // Нет штрихкода (только 5 колонок)
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $whitelist->load();

    expect($whitelist->getCount())->toBe(1);
    expect($whitelist->isAllowed(['2200569']))->toBeTrue();
});

test('handles duplicate barcodes in csv', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    $csv .= "2,\"Апельсины другие\",-,\"160,0\",кг,2200569\n"; // Тот же штрихкод
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $whitelist->load();

    // Должен считаться как 1 уникальный
    expect($whitelist->getCount())->toBe(1);
});

test('skips rows with empty barcode', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    $csv .= "2,Бананы,-,\"140,0\",кг,\n"; // Пустой штрихкод
    file_put_contents($this->tempCsvPath, $csv);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $whitelist->load();

    expect($whitelist->getCount())->toBe(1);
});
