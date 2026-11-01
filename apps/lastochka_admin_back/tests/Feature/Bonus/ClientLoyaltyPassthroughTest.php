<?php

namespace Tests\Feature\Bonus;

use App\Models\Client;
use App\Transformers\ClientEventTransformer;
use Tests\TestCase;

class ClientLoyaltyPassthroughTest extends TestCase
{
    /** @test */
    public function test_passes_loyalty_id_through_client_transformer(): void
    {
        $client = new Client();
        $client->forceFill(['loyalty_id' => 'LTY-CC', 'phone' => '+79991112233']);

        $payload = app(ClientEventTransformer::class)->toEvent($client);
        $this->assertSame('LTY-CC', $payload['loyalty_id']);

        $attrs = app(ClientEventTransformer::class)->fromEvent([
            'phone' => '+79991112233', 'loyalty_id' => 'LTY-DD',
        ]);
        $this->assertSame('LTY-DD', $attrs['loyalty_id']);
    }
}
