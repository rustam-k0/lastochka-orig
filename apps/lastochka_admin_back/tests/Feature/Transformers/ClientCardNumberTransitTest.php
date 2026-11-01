<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Transformers\ClientEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClientCardNumberTransitTest extends TestCase
{
    use DatabaseTransactions;

    private ClientEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ClientEventTransformer();
    }

    /** @test */
    public function to_event_carries_card_number(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Card',
            'loyalty_id' => 'LTY-01ABC',
            'card_number' => '07123456',
            'is_authorized' => true,
        ]);

        $payload = $this->transformer->toEvent($client);

        $this->assertSame('07123456', $payload['card_number']);
    }

    /** @test */
    public function from_event_transits_card_number(): void
    {
        $attributes = $this->transformer->fromEvent([
            'phone' => '+79990001122',
            'name' => 'Иван',
            'card_number' => '07123456',
        ]);

        $this->assertSame('07123456', $attributes['card_number']);
    }
}
