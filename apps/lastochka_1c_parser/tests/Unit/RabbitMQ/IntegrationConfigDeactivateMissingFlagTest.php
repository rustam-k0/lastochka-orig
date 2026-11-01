<?php

use App\RabbitMQ\Transformers\IntegrationConfigEventTransformer;

/**
 * Консьюмер 1c_parser должен принимать deactivate_missing_enabled из события
 * integration_config.* (чекбокс «Удалять товары, не попавшие в полную выгрузку 1С»
 * в форме 1С-интеграции admin_back).
 */
test('fromEvent принимает deactivate_missing_enabled когда поле есть в событии', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
        'deactivate_missing_enabled' => true,
    ]);

    expect($attributes)->toHaveKey('deactivate_missing_enabled');
    expect($attributes['deactivate_missing_enabled'])->toBeTrue();
});

test('fromEvent приводит deactivate_missing_enabled к bool', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
        'deactivate_missing_enabled' => 0,
    ]);

    expect($attributes['deactivate_missing_enabled'])->toBeFalse();
});

test('fromEvent не добавляет deactivate_missing_enabled когда поля нет в событии', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
    ]);

    expect($attributes)->not->toHaveKey('deactivate_missing_enabled');
});
