<?php

namespace App\Services\Exchange1C;

use App\Models\BarcodePrefix;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Импорт префиксов штрихкодов от 1С (мастер префиксов — 1С, LASTOCHKI-JAMI-12013).
 *
 * Контракт (согласуется с 1С-специалистом), поддерживаются оба места:
 *  - отдельный файл prefixes___*.xml;
 *  - секция в файле статусов заказов orders___*.xml.
 *
 * Формат секции:
 *  <ПрефиксыШтрихкодов>
 *    <Префикс><Тип>КартаЛояльности</Тип><Значение>07</Значение></Префикс>
 *  </ПрефиксыШтрихкодов>
 *
 * При изменении значения публикуется barcode_prefixes.updated → storefront
 * актуализирует префикс генерации новых карт.
 */
class PrefixImportService
{
    /** Маппинг «Тип» из 1С → внутренний type. Неизвестные типы сохраняются как mb_strtolower(Тип). */
    protected const TYPE_MAP = [
        'карталояльности' => BarcodePrefix::TYPE_CARD,
        'карта' => BarcodePrefix::TYPE_CARD,
    ];

    public function importFromFile(string $fileName): int
    {
        if (!file_exists($fileName)) {
            return 0;
        }

        $xml = simplexml_load_string((string) file_get_contents($fileName));
        if ($xml === false) {
            Log::channel('1c-exchange')->warning("PrefixImport: невалидный XML: {$fileName}");
            return 0;
        }

        return $this->importFromArray(json_decode((string) json_encode($xml), true) ?: []);
    }

    /**
     * @param  array<string, mixed>  $data  декодированный XML «КоммерческаяИнформация»
     * @return int число обновлённых префиксов
     */
    public function importFromArray(array $data): int
    {
        $section = $data['ПрефиксыШтрихкодов']['Префикс'] ?? null;
        if ($section === null) {
            return 0;
        }

        // Один префикс приходит объектом, несколько — массивом
        if (isset($section['Тип']) || isset($section['Значение'])) {
            $section = [$section];
        }

        $updated = 0;

        foreach ($section as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Пустой XML-элемент (<Значение></Значение>) после simplexml→json_decode
            // становится [] — прямой (string) кинул бы «Array to string conversion»
            // ДО per-row try/catch и валил бы весь файл. is_scalar-guard → '' → скип.
            $rawType = is_scalar($row['Тип'] ?? null) ? trim((string) $row['Тип']) : '';
            $value = is_scalar($row['Значение'] ?? null) ? trim((string) $row['Значение']) : '';

            if ($rawType === '' || $value === '') {
                continue;
            }

            $type = self::TYPE_MAP[mb_strtolower($rawType)] ?? mb_strtolower($rawType);

            try {
                $prefix = BarcodePrefix::firstOrNew(['type' => $type]);
                if ($prefix->exists && $prefix->value === $value) {
                    continue; // без изменений — не публикуем
                }

                // save() и publishUpdated() — в одной транзакции (паттерн Task 12,
                // OrderExportHandler::publishLoyaltyConfirmations). Если публикация
                // (EventOutbox::create) упадёт — откатываем и save префикса: значение
                // в БД останется прежним, и следующий файл обмена снова увидит
                // расхождение и повторит попытку. Без этого save-раньше-publish
                // навсегда потерял бы событие (значение уже совпадает — importFromArray
                // молча скипнет строку при повторном заходе).
                DB::transaction(function () use ($prefix, $value) {
                    $prefix->value = $value;
                    $prefix->save();

                    $this->publishUpdated($prefix);
                });

                $updated++;
            } catch (\Throwable $e) {
                // Один битый/гонящийся префикс не должен валить остальные строки секции
                // (паттерн BonusOperationImportService::importFromDocuments).
                Log::channel('1c-exchange')->error('PrefixImport: строка не обработана', [
                    'type' => $type,
                    'value' => $value,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($updated > 0) {
            Log::channel('1c-exchange')->info("Обновлены префиксы штрихкодов из 1С: {$updated}");
        }

        return $updated;
    }

    protected function publishUpdated(BarcodePrefix $prefix): void
    {
        $eventId = (string) Str::uuid();

        EventOutbox::create([
            'entity_type' => 'barcode_prefixes',
            'entity_id' => $prefix->id,
            'event_type' => 'updated',
            'event_id' => $eventId,
            'routing_key' => 'barcode_prefixes.updated',
            'payload' => [
                'event_id' => $eventId,
                'event_type' => 'updated',
                'event_source' => config('rabbitmq-events.source'),
                'type' => $prefix->type,
                'value' => $prefix->value,
            ],
        ]);
    }
}
