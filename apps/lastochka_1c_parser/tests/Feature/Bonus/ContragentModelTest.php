<?php

use App\Models\Contragent;

it('fills contragent attributes', function () {
    $c = new Contragent();
    $c->forceFill([
        'external_id' => 'GUID-1',
        'loyalty_id' => 'LTY-CC',
        'name' => 'Иван',
        'phone' => '+79990001122',
        'bonus_balance' => 300,
    ]);

    expect($c->loyalty_id)->toBe('LTY-CC')
        ->and((int) $c->bonus_balance)->toBe(300)
        ->and($c->name)->toBe('Иван'); // кириллица цела
});
