<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * Регрессия: LASTOCHKI-JAMI-10114 / Phase 2 Task 2.1.
 * 1С-категории должны публиковаться отдельным routing_key_prefix `category_1c`,
 * чтобы admin_back подписывал их как отдельный read-only справочник
 * и не пересекался с `category.*` от storefront/admin (showcase-категории).
 */
test('categories entity config uses category_1c routing key prefix', function () {
    $prefix = config('rabbitmq-events.entities.categories.routing_key_prefix');

    expect($prefix)->toBe('category_1c');
});
