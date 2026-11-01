<?php

namespace App\GraphQL\Mutations\Client;

use App\Models\Client;
use App\Models\ClientBonusTransaction;
use App\Services\ClientBonusService;
use App\Services\ModuleConfigService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientBonusOperationCreate
{
    public function __construct(
        private ClientBonusService $bonusService,
    ) {}

    /**
     * @param null $_
     * @param array{clientId: int, type: string, amount: int, comment: string} $args
     */
    public function __invoke($_, array $args)
    {
        if (!ModuleConfigService::isBonusesEnabled()) {
            throw new Error('Модуль «Бонусная система» выключен');
        }

        $client = Client::findOrFail($args['clientId']);

        if (!$client->phone) {
            throw new Error('У клиента не указан телефон, операция невозможна');
        }

        $comment = trim($args['comment']);
        if ($comment === '') {
            throw new Error('Укажите комментарий к операции');
        }

        $amount = (int) $args['amount'];

        if ($args['type'] === 'spend') {
            $available = $client->available_bonus_balance;
            if ($amount > $available) {
                throw new Error("Доступно к списанию: {$available}");
            }
        }

        DB::transaction(function () use ($client, $args, $amount, $comment) {
            $tx = ClientBonusTransaction::create([
                'client_id' => $client->id,
                'operation_id' => Str::uuid()->toString(),
                'type' => $args['type'],
                'amount' => $amount,
                'source' => 'manual',
                'description' => $comment,
                'admin_id' => auth()->id(),
                'status' => ClientBonusTransaction::STATUS_PENDING,
            ]);

            $this->bonusService->publishOperationRequested($tx);

            return $tx;
        });

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
