<?php

namespace App\GraphQL\Mutations\Client;

use App\Models\Client;
use App\Services\ClientB2bService;

final class ClientCreate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $args["is_authorized"] = true;

    $client = Client::create($args);

    // Если создаётся B2B-клиент, публикуем выделенное событие
    if (!empty($args['is_b2b'])) {
      app(ClientB2bService::class)->publishB2bStatusChanged($client);
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
