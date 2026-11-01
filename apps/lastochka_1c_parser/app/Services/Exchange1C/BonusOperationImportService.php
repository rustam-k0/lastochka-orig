<?php

namespace App\Services\Exchange1C;

use App\Models\BonusOperation;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BonusOperationImportService
{
    /**
     * Импорт офлайн-операций из распарсенных документов CommerceML.
     *
     * @param array $documents Массив документов «Бонусная операция» из 1С
     * @return int Количество применённых офлайн-операций
     */
    public function importFromDocuments(array $documents): int
    {
        $count = 0;

        foreach ($documents as $document) {
            try {
                $req = $this->extractRequisites($document);

                $operationId = $req['ИД операции'] ?? null;
                $loyaltyId   = $req['ИД лояльности'] ?? null;
                $type        = $this->mapType($req['Тип'] ?? '');
                $amount      = (int) ($req['Баллы'] ?? 0);

                if (! $operationId || ! $loyaltyId || $amount <= 0 || ! $type) {
                    continue;
                }

                // Идемпотентность — пропускаем уже применённую операцию
                if (BonusOperation::where('operation_id', $operationId)->exists()) {
                    continue;
                }

                DB::transaction(function () use ($operationId, $loyaltyId, $type, $amount, $req) {
                    $op = BonusOperation::create([
                        'operation_id'      => $operationId,
                        'loyalty_id'        => $loyaltyId,
                        'type'              => $type,
                        'amount'            => $amount,
                        'source'            => 'offline',
                        'order_external_id' => $req['ИД заказа'] ?? null,
                        'direction'         => BonusOperation::DIRECTION_INBOUND,
                        'status'            => 'applied',
                        'payload'           => $req,
                    ]);

                    // Публикуем в storefront через ПРЯМОЙ EventOutbox.
                    // CustomEventPublisher::dispatch(..., 'created', ...) выбрасывает
                    // InvalidEventTypeException, т.к. пакет резервирует created/updated/deleted.
                    $eventId = Str::uuid()->toString();
                    EventOutbox::create([
                        'entity_type' => 'bonus_operations',
                        'entity_id'   => $op->id,
                        'event_type'  => 'created',
                        'event_id'    => $eventId,
                        'routing_key' => 'bonus_operation.created',
                        'payload'     => [
                            'event_id'         => $eventId,
                            'event_source'     => config('rabbitmq-events.source'),
                            'operation_id'     => $operationId,
                            'loyalty_id'       => $loyaltyId,
                            'type'             => $type,
                            'amount'           => $amount,
                            'operation_source' => 'offline',
                            'order_external_id' => $op->order_external_id,
                        ],
                    ]);
                });

                $count++;
            } catch (\Throwable $e) {
                // Один битый/гонящийся документ не должен валить весь файл обмена
                // (паттерн OrderStatusSyncService::importOrderStatuses).
                Log::channel('1c-exchange')->error('Bonus op import: document failed', ['error' => $e->getMessage()]);
                continue;
            }
        }

        Log::channel('1c-exchange')->info('Bonus offline operations imported', ['count' => $count]);

        return $count;
    }

    private function mapType(string $raw): ?string
    {
        return match (mb_strtolower(trim($raw))) {
            'earn', 'начисление'  => 'earn',
            'spend', 'списание'   => 'spend',
            'refund', 'возврат'   => 'refund',
            'cancel', 'отмена'    => 'cancel',
            default               => null,
        };
    }

    /**
     * Реюз паттерна OrderStatusSyncService::extractRequisites.
     */
    private function extractRequisites(array $document): array
    {
        $result = [];

        if (! isset($document['ЗначенияРеквизитов']['ЗначениеРеквизита'])) {
            return $result;
        }

        $values = $document['ЗначенияРеквизитов']['ЗначениеРеквизита'];

        // Если один элемент — превращаем в массив
        if (isset($values['Наименование'])) {
            $values = [$values];
        }

        foreach ($values as $requisite) {
            if (! is_array($requisite)) {
                continue;
            }
            $name = $requisite['Наименование'] ?? null;
            if ($name !== null) {
                $result[$name] = $requisite['Значение'] ?? null;
            }
        }

        return $result;
    }
}
