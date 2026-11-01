<?php

namespace App\GraphQL\Mutations\Client;

use App\Models\Client;
use App\Models\Status;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

final class ClientDelete
{
    /**
     * Статусы "активного" заказа — при наличии таких у клиента удаление запрещено.
     */
    private const ACTIVE_ORDER_STATUSES = [
        Status::ORDER_CREATED,      // 1
        Status::ORDER_PREPARING,    // 2
        Status::ORDER_ASSEMBLED,    // 3
        Status::ORDER_IN_DELIVERY,  // 4
    ];

    /**
     * @param null $_
     * @param array{id: int} $args
     * @return array{success: bool, orders_count: int}
     */
    public function __invoke($_, array $args): array
    {
        // withoutGlobalScope('onlyAuthorized') — админ должен уметь удалять даже дубль
        // клиента с is_authorized=false (на dev такие существуют из-за старых данных).
        $client = Client::withoutGlobalScope('onlyAuthorized')->findOrFail($args['id']);

        if ($client->trashed()) {
            throw new Error('Клиент уже удалён');
        }

        $activeOrderIds = $client->orders()
            ->whereIn('status_id', self::ACTIVE_ORDER_STATUSES)
            ->pluck('id')
            ->toArray();

        if (count($activeOrderIds) > 0) {
            throw new Error(
                message: 'Нельзя удалить клиента с активными заказами. '
                    . 'Завершите или отмените заказы: #' . implode(', #', $activeOrderIds),
                extensions: [
                    'code' => 'CLIENT_HAS_ACTIVE_ORDERS',
                    'active_order_ids' => $activeOrderIds,
                ],
            );
        }

        $ordersCount = $client->orders()->count();

        DB::transaction(function () use ($client) {
            // QueryBuilder::delete() на HasMany — observers ClientCard НЕ вызываются.
            // Это намеренно: сейчас client_cards не в config/rabbitmq-events.php.
            // Если когда-нибудь карты появятся в outbox-конфиге — переписать на
            // foreach ($client->cards as $card) { $card->delete(); } иначе события утекут.
            $client->cards()->delete();

            // Eloquent $model->delete() — триггерит `deleting`/`deleted` события,
            // на которые подписан RabbitMQ observer → customer.deleted улетает в outbox.
            $client->delete();
        });

        return [
            'success' => true,
            'orders_count' => $ordersCount,
        ];
    }
}
