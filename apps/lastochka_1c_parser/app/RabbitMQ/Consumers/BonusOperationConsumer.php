<?php

namespace App\RabbitMQ\Consumers;

use App\Models\BonusOperation;
use App\RabbitMQ\DTO\BonusOperationDTO;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class BonusOperationConsumer
{
    public function handle(AMQPMessage $message): bool
    {
        try {
            $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $dto = BonusOperationDTO::fromMessage($data);

            if ($dto->operationId === '' || ! $dto->loyaltyId) {
                return true;
            }
            if (BonusOperation::where('operation_id', $dto->operationId)->exists()) {
                return true; // идемпотентность
            }

            BonusOperation::create(self::toRow($dto));

            return true;
        } catch (Throwable $e) {
            Log::channel('rabbitmq-orders')->error('BonusOperationConsumer error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public static function toRow(BonusOperationDTO $dto): array
    {
        return [
            'operation_id'     => $dto->operationId,
            'loyalty_id'       => $dto->loyaltyId,
            'type'             => $dto->type,
            'amount'           => $dto->amount,
            'source'           => $dto->source,
            'order_external_id' => $dto->orderExternalId,
            'direction'        => BonusOperation::DIRECTION_OUTBOUND,
            'status'           => 'pending',
        ];
    }
}
