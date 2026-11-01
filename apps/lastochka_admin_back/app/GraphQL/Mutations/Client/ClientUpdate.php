<?php

namespace App\GraphQL\Mutations\Client;

use App\Models\Client;

final class ClientUpdate
{
    public function __invoke($_, array $args)
    {
        $client = Client::findOrFail($args['id']);

        $updateData = collect($args)
            ->except(['id'])
            ->toArray();

        // Нормализуем пустую строку admin_note в null, чтобы не хранить мусор
        if (array_key_exists('admin_note', $updateData) && $updateData['admin_note'] === '') {
            $updateData['admin_note'] = null;
        }

        $client->update($updateData);

        return [
            'code' => 200,
            'message' => 'OK'
        ];
    }
}
