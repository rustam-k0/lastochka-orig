<?php

use App\RabbitMQ\Transformers\IntegrationConfigEventTransformer;

/**
 * Консьюмер 1c_parser должен принимать sync_images_enabled из события
 * integration_config.* (публикуется admin_back при сохранении 1С-интеграции).
 */
test('fromEvent принимает sync_images_enabled когда поле есть в событии', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
        'sync_images_enabled' => true,
    ]);

    expect($attributes)->toHaveKey('sync_images_enabled');
    expect($attributes['sync_images_enabled'])->toBeTrue();
});

test('fromEvent приводит sync_images_enabled к bool', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
        'sync_images_enabled' => 0,
    ]);

    expect($attributes['sync_images_enabled'])->toBeFalse();
});

test('fromEvent не добавляет sync_images_enabled когда поля нет в событии', function () {
    $transformer = new IntegrationConfigEventTransformer();

    $attributes = $transformer->fromEvent([
        'integration_type' => '1c',
    ]);

    expect($attributes)->not->toHaveKey('sync_images_enabled');
});
