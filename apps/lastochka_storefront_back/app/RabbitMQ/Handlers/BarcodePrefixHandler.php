<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Models\StorefrontSetting;

final class BarcodePrefixHandler
{
    /**
     * barcode_prefixes.updated от 1c_parser (мастер префиксов — 1С).
     * Сейчас используется только префикс карт (type=card): актуализирует
     * настройку, с которой LoyaltyCardService генерит новые card_number.
     * Уже выданные карты не перевыпускаются.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handle(array $eventData): string
    {
        $payload = $eventData['payload'] ?? $eventData;
        $type = $payload['type'] ?? null;
        $value = $payload['value'] ?? null;

        if ($type !== 'card' || !is_string($value) || $value === '') {
            return 'ack';
        }

        // Префикс — цифры разумной длины; иначе card_number (prefix + суффикс)
        // может превысить varchar(16) при генерации новых карт → SQL-ошибка.
        if (!ctype_digit($value) || mb_strlen($value) > 4) {
            return 'ack';
        }

        StorefrontSetting::updateOrCreate(
            ['key' => (string) config('loyalty.card_prefix_setting_key', 'loyalty_card_prefix')],
            ['value' => $value, 'type' => 'string'],
        );

        return 'ack';
    }
}
