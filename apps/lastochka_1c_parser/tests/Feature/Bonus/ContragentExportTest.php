<?php

use App\Models\Contragent;
use App\Services\Exchange1C\ContragentExportHandler;

it('builds contragent xml with loyalty requisites', function () {
    $c = new Contragent();
    $c->forceFill([
        'external_id' => 'GUID-7', 'loyalty_id' => 'LTY-XYZ',
        'name' => 'Иван Иванов', 'phone' => '+79991230011', 'bonus_balance' => 540,
    ]);

    $xml = (new ContragentExportHandler())->buildXml([$c]);

    expect($xml)->toContain('<Контрагент>')
        ->and($xml)->toContain('LTY-XYZ')
        ->and($xml)->toContain('Баланс баллов')
        ->and($xml)->toContain('540')
        ->and($xml)->toContain('Иван Иванов'); // кириллица не побита
});

it('includes Номер карты requisite when card_number is present', function () {
    $c = new Contragent();
    $c->forceFill([
        'external_id' => 'GUID-8', 'loyalty_id' => 'LTY-CARD',
        'name' => 'Мария Карточкина', 'phone' => '+79990003344',
        'bonus_balance' => 100, 'card_number' => '07123456',
    ]);

    $xml = (new ContragentExportHandler())->buildXml([$c]);

    expect($xml)->toContain('Номер карты')
        ->and($xml)->toContain('07123456');
});

it('omits Номер карты requisite when card_number is absent', function () {
    $c = new Contragent();
    $c->forceFill([
        'external_id' => 'GUID-9', 'loyalty_id' => 'LTY-NOCARD',
        'name' => 'Олег Безкарточкин', 'phone' => '+79990004455',
        'bonus_balance' => 50,
    ]);

    $xml = (new ContragentExportHandler())->buildXml([$c]);

    expect($xml)->not->toContain('Номер карты');
});
