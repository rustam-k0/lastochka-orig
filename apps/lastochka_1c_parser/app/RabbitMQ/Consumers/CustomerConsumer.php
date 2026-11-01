<?php

namespace App\RabbitMQ\Consumers;

use App\Models\Contragent;
use App\Models\IntegrationConfig;
use App\RabbitMQ\DTO\CustomerSyncDTO;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class CustomerConsumer
{
    public function handle(AMQPMessage $message): bool
    {
        try {
            $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $dto = CustomerSyncDTO::fromMessage($data);

            if (! $dto->loyaltyId && ! $dto->phone) {
                return true; // нечего матчить
            }

            $query = Contragent::query();
            if ($dto->loyaltyId) {
                $query->where('loyalty_id', $dto->loyaltyId);
            } else {
                $query->where('phone', $dto->phone);
            }
            $contragent = $query->first() ?? new Contragent();

            $contragent->forceFill(array_filter([
                'loyalty_id' => $dto->loyaltyId ?? $contragent->loyalty_id,
                'card_number' => $dto->cardNumber ?? $contragent->card_number,
                'name' => $dto->name,
                'surname' => $dto->surname,
                'patronymic' => $dto->patronymic,
                'phone' => $dto->phone,
                'email' => $dto->email,
                'birthday' => $dto->birthday,
                'gender' => $dto->gender,
                'shop_id' => IntegrationConfig::resolveShopIdByCode($dto->shopCode),
                'received_at' => now(),
            ], fn ($v) => $v !== null));
            // Обновляем баланс ТОЛЬКО когда поле реально пришло в payload.
            // Частичный customer.updated без bonus_balance не должен затирать
            // реальный баланс нулём (иначе уедет в 1С как «Баланс баллов»=0).
            if ($dto->bonusBalance !== null) {
                $contragent->bonus_balance = $dto->bonusBalance;
            }
            $contragent->save();

            return true;
        } catch (Throwable $e) {
            Log::channel('rabbitmq-orders')->error('CustomerConsumer error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
