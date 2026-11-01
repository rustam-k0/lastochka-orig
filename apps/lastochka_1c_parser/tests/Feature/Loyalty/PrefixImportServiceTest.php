<?php

use App\Models\BarcodePrefix;
use App\Services\Exchange1C\PrefixImportService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports card prefix from section array and publishes update', function () {
    $count = app(PrefixImportService::class)->importFromArray([
        'ПрефиксыШтрихкодов' => [
            'Префикс' => ['Тип' => 'КартаЛояльности', 'Значение' => '09'],
        ],
    ]);

    expect($count)->toBe(1)
        ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('09');

    $outbox = EventOutbox::where('routing_key', 'barcode_prefixes.updated')->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['type'])->toBe('card')
        ->and($outbox->payload['value'])->toBe('09');
});

it('imports multiple prefixes', function () {
    $count = app(PrefixImportService::class)->importFromArray([
        'ПрефиксыШтрихкодов' => [
            'Префикс' => [
                ['Тип' => 'КартаЛояльности', 'Значение' => '07'],
                ['Тип' => 'Весовой', 'Значение' => '22'],
            ],
        ],
    ]);

    expect($count)->toBe(2)
        ->and(BarcodePrefix::count())->toBe(2)
        ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('07');
});

it('does not republish unchanged prefix', function () {
    $service = app(PrefixImportService::class);
    $data = ['ПрефиксыШтрихкодов' => ['Префикс' => ['Тип' => 'Карта', 'Значение' => '07']]];

    $service->importFromArray($data);
    $second = $service->importFromArray($data);

    expect($second)->toBe(0)
        ->and(EventOutbox::where('routing_key', 'barcode_prefixes.updated')->count())->toBe(1);
});

it('returns zero when section is missing', function () {
    expect(app(PrefixImportService::class)->importFromArray(['Документ' => []]))->toBe(0);
});

it('skips prefix with empty XML value decoded as array without throwing', function () {
    // <Значение></Значение> после simplexml→json_decode становится [] —
    // прямой (string) [] кинул бы «Array to string conversion»
    $count = app(PrefixImportService::class)->importFromArray([
        'ПрефиксыШтрихкодов' => [
            'Префикс' => ['Тип' => 'КартаЛояльности', 'Значение' => []],
        ],
    ]);

    expect($count)->toBe(0)
        ->and(BarcodePrefix::count())->toBe(0);
});

it('skips empty XML tag in real file but still imports the valid sibling prefix', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПрефиксыШтрихкодов>
        <Префикс>
            <Тип>КартаЛояльности</Тип>
            <Значение></Значение>
        </Префикс>
        <Префикс>
            <Тип>Весовой</Тип>
            <Значение>22</Значение>
        </Префикс>
    </ПрефиксыШтрихкодов>
</КоммерческаяИнформация>
XML;

    $path = sys_get_temp_dir() . '/prefixes___empty_tag_' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    try {
        $count = app(PrefixImportService::class)->importFromFile($path);

        // Битая строка (пустое Значение → []) скипается без исключения,
        // валидный сосед импортируется
        expect($count)->toBe(1)
            ->and(BarcodePrefix::count())->toBe(1)
            ->and(BarcodePrefix::where('type', 'card')->exists())->toBeFalse()
            ->and(BarcodePrefix::where('type', 'весовой')->value('value'))->toBe('22');
    } finally {
        @unlink($path);
    }
});

it('imports card prefix from a real prefixes___*.xml file', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПрефиксыШтрихкодов>
        <Префикс>
            <Тип>КартаЛояльности</Тип>
            <Значение>11</Значение>
        </Префикс>
    </ПрефиксыШтрихкодов>
</КоммерческаяИнформация>
XML;

    $path = sys_get_temp_dir() . '/prefixes___test_' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    try {
        $count = app(PrefixImportService::class)->importFromFile($path);

        expect($count)->toBe(1)
            ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('11');
    } finally {
        @unlink($path);
    }
});

it('routes prefixes___*.xml file through CatalogImportHandler to PrefixImportService', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПрефиксыШтрихкодов>
        <Префикс>
            <Тип>КартаЛояльности</Тип>
            <Значение>13</Значение>
        </Префикс>
    </ПрефиксыШтрихкодов>
</КоммерческаяИнформация>
XML;

    $path = sys_get_temp_dir() . '/prefixes___routing_' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    try {
        $handler = new \App\Services\Exchange1C\CatalogImportHandler();
        $result = $handler->import($path);

        expect($result)->toBe('success')
            ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('13');
    } finally {
        @unlink($path);
    }
});

it('rolls back the prefix value when publish fails, so the next import retries it', function () {
    BarcodePrefix::create(['type' => 'card', 'value' => '05']);

    $failingService = new class extends PrefixImportService {
        protected function publishUpdated(BarcodePrefix $prefix): void
        {
            throw new RuntimeException('outbox insert failed');
        }
    };

    $data = ['ПрефиксыШтрихкодов' => ['Префикс' => ['Тип' => 'Карта', 'Значение' => '06']]];

    $count = $failingService->importFromArray($data);

    expect($count)->toBe(0)
        ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('05')
        ->and(EventOutbox::where('routing_key', 'barcode_prefixes.updated')->count())->toBe(0);

    // Следующий (успешный) заход снова видит расхождение и повторяет попытку
    $retryCount = app(PrefixImportService::class)->importFromArray($data);

    expect($retryCount)->toBe(1)
        ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('06')
        ->and(EventOutbox::where('routing_key', 'barcode_prefixes.updated')->count())->toBe(1);
});

it('imports card prefix embedded in an orders status file via OrderStatusSyncService::importFromFile', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПрефиксыШтрихкодов>
        <Префикс>
            <Тип>КартаЛояльности</Тип>
            <Значение>21</Значение>
        </Префикс>
    </ПрефиксыШтрихкодов>
</КоммерческаяИнформация>
XML;

    $path = sys_get_temp_dir() . '/orders___prefix_section_' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    try {
        $service = new \App\Services\Exchange1C\OrderStatusSyncService();
        $processed = $service->importFromFile($path);

        // Нет документов заказов в файле — processed=0, но секция префиксов всё равно разобрана
        expect($processed)->toBe(0)
            ->and(BarcodePrefix::where('type', 'card')->value('value'))->toBe('21');
    } finally {
        @unlink($path);
    }
});
