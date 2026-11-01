<?php

namespace App\GraphQL\Mutations\Client;

use App\Models\Client;
use App\Services\ClientB2bService;

final class UpdateClientB2bStatus
{
    public function __construct(
        private ClientB2bService $b2bService,
    ) {}

    /**
     * @param null $_
     * @param array{clientId: int, isB2b: bool} $args
     */
    public function __invoke($_, array $args)
    {
        $client = Client::findOrFail($args['clientId']);

        $client->update([
            'is_b2b' => $args['isB2b'],
        ]);

        $this->b2bService->publishB2bStatusChanged($client);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
