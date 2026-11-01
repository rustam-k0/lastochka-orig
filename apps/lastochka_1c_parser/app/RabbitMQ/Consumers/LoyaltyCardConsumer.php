<?php

namespace App\RabbitMQ\Consumers;

use App\Models\IntegrationConfig;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * loyalty_card.created от storefront: создаёт спец-заказ «Лояльность» (0 руб)
 * для выгрузки в 1С — 1С по нему заводит карту лояльности (LASTOCHKI-JAMI-12013).
 */
class LoyaltyCardConsumer
{
    public function handle(AMQPMessage $message): bool
    {
        try {
            $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $payload = $data['payload'] ?? $data;

            self::createLoyaltyOrder(is_array($payload) ? $payload : []);

            return true;
        } catch (Throwable $e) {
            Log::channel('rabbitmq-orders')->error('LoyaltyCardConsumer error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Идемпотентно (по card_number) создаёт заказ «Лояльность».
     *
     * @param  array<string, mixed>  $payload
     * @return Order|null null — если payload неполный или заказ уже существует
     */
    public static function createLoyaltyOrder(array $payload): ?Order
    {
        $cardNumber = $payload['card_number'] ?? null;
        $loyaltyId = $payload['loyalty_id'] ?? null;

        if (!$cardNumber || !$loyaltyId) {
            return null;
        }

        if (Order::where('source', Order::SOURCE_LOYALTY_CARD)->where('card_number', $cardNumber)->exists()) {
            return null; // идемпотентность: один заказ «Лояльность» на карту
        }

        return DB::transaction(function () use ($payload, $cardNumber, $loyaltyId) {
            $order = Order::create([
                'number' => Order::generateNumber(),
                // Сразу «Собран» — заказ попадает в forExport и уходит
                // при ближайшем поллинге 1С (интервал 20 сек — настройка 1С).
                'status' => Order::STATUS_ASSEMBLED,
                'source' => Order::SOURCE_LOYALTY_CARD,
                'shop_id' => IntegrationConfig::resolveShopIdByCode($payload['shop_code'] ?? null),
                'customer_name' => ($payload['name'] ?? null) ?: ('Клиент ' . $cardNumber),
                // customer_phone NOT NULL: событие без телефона иначе падало бы в SQL error
                // → ретраи → DLQ. Пустая строка безопасна — formatPhoneFor1C для '' вернёт ''.
                'customer_phone' => (string) (($payload['phone'] ?? null) ?: ''),
                'total' => 0,
                // Заказ СОЗДАНИЯ карты: в 1С карты ещё нет, поэтому «Признак наличия
                // карты» = Нет — это сигнал 1С «карту надо завести». Сам номер уходит
                // отдельным реквизитом «Номер карты» (см. OrderExportHandler::buildRequisites,
                // где для source=loyalty_card номер выгружается даже при has_card=false).
                'has_card' => false,
                'card_number' => $cardNumber,
                'loyalty_id' => $loyaltyId,
                'received_at' => now(),
            ]);

            $order->items()->create([
                'external_id' => (string) config('exchange1c.loyalty_nomenclature_id', 'loyalty'),
                'name' => (string) config('exchange1c.loyalty_nomenclature_name', 'Лояльность'),
                'quantity' => 1,
                'price' => 0,
                'total' => 0,
            ]);

            Log::channel('1c-exchange')->info('Создан заказ «Лояльность» для карты', [
                'order_id' => $order->id,
                'card_number' => $cardNumber,
            ]);

            return $order;
        });
    }
}
