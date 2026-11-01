<?php

namespace App\RabbitMQ\Handlers;

use App\Models\Client;
use App\Models\ClientBonusTransaction;
use App\Support\EventDateTime;
use Illuminate\Support\Facades\Log;

/**
 * Закрывает pending-запись журнала фактом bonus_operation.created или
 * отказом bonus_operation.rejected из storefront_back.
 *
 * Баланс клиента здесь не обновляется: зеркало bonus_balance/bonus_reserved
 * пишет только событие customer.updated (ClientEventTransformer::fromEvent()).
 * Два пишущих источника создали бы гонку — задержавшееся клиентское событие
 * со старым балансом перезаписало бы более свежее значение из факта операции.
 */
class ClientBonusOperationHandler
{
    /**
     * @param array<string, mixed> $eventData
     */
    public function handle(array $eventData): string
    {
        $payload = $eventData['payload'] ?? $eventData;
        $eventType = $eventData['event_type'] ?? ($payload['event_type'] ?? null);
        $operationId = $payload['operation_id'] ?? null;

        if (!$operationId) {
            Log::warning('Bonus operation event without operation_id', ['payload' => $payload]);

            return 'ack';
        }

        if ($eventType === 'rejected' || array_key_exists('reason', $payload)) {
            return $this->handleRejection($operationId, $payload);
        }

        return $this->handleFact($operationId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleFact(string $operationId, array $payload): string
    {
        $existing = ClientBonusTransaction::where('operation_id', $operationId)->first();

        if (!$existing) {
            $client = $this->resolveClient($payload);

            if (!$client) {
                Log::info('Bonus operation fact for unknown client, skipped', [
                    'operation_id' => $operationId,
                    'loyalty_id' => $payload['loyalty_id'] ?? null,
                ]);

                return 'ack';
            }

            $existing = new ClientBonusTransaction([
                'client_id' => $client->id,
                'operation_id' => $operationId,
            ]);
        }

        $existing->fill([
            'type' => $payload['type'] ?? $existing->type,
            'amount' => (int) ($payload['amount'] ?? $existing->amount),
            'balance_after' => isset($payload['balance_after']) ? (int) $payload['balance_after'] : $existing->balance_after,
            'source' => $payload['operation_source'] ?? $existing->source ?? 'online',
            'description' => $payload['description'] ?? $existing->description,
            'order_external_id' => $payload['order_external_id'] ?? $existing->order_external_id,
            // Момент операции приводим к таймзоне приложения: витрина шлёт его
            // с офсетом, а колонка таймзону не хранит (MID-272)
            'occurred_at' => EventDateTime::fromPayload($payload['occurred_at'] ?? null) ?? now(),
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'reject_reason' => null,
            'reject_message' => null,
        ]);

        $existing->save();

        return 'ack';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleRejection(string $operationId, array $payload): string
    {
        $tx = ClientBonusTransaction::where('operation_id', $operationId)->first();

        if (!$tx) {
            Log::warning('Bonus operation rejection for unknown operation', ['operation_id' => $operationId]);

            return 'ack';
        }

        $tx->forceFill([
            'status' => ClientBonusTransaction::STATUS_REJECTED,
            'reject_reason' => $payload['reason'] ?? 'unknown',
            'reject_message' => $payload['message'] ?? null,
        ])->save();

        return 'ack';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveClient(array $payload): ?Client
    {
        $loyaltyId = $payload['loyalty_id'] ?? null;

        if ($loyaltyId) {
            $byLoyalty = Client::withoutGlobalScopes()->where('loyalty_id', $loyaltyId)->first();
            if ($byLoyalty) {
                return $byLoyalty;
            }
        }

        $phone = $payload['client_phone'] ?? null;

        if (!$phone) {
            return null;
        }

        return Client::withoutGlobalScopes()->where('phone', $phone)->first();
    }
}
