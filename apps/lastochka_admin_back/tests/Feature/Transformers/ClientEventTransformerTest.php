<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Type;
use App\Transformers\ClientEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClientEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    private ClientEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ClientEventTransformer();
    }

    /** @test */
    public function from_event_passes_through_only_fields_that_are_present_in_payload(): void
    {
        $data = [
            'event_id' => 'abc',
            'id' => '1',
            'phone' => '+79000000001',
        ];

        $attributes = $this->transformer->fromEvent($data);

        $this->assertSame('+79000000001', $attributes['phone']);
        $this->assertTrue($attributes['is_authorized']);
        $this->assertArrayNotHasKey('name', $attributes);
        $this->assertArrayNotHasKey('surname', $attributes);
        $this->assertArrayNotHasKey('patronymic', $attributes);
        $this->assertArrayNotHasKey('email', $attributes);
        $this->assertArrayNotHasKey('birthday', $attributes);
        $this->assertArrayNotHasKey('gender_type_id', $attributes);
    }

    /** @test */
    public function from_event_preserves_explicit_nulls_that_are_present_in_payload(): void
    {
        $data = [
            'id' => '1',
            'phone' => '+79000000002',
            'email' => null,
            'name' => 'Иван',
        ];

        $attributes = $this->transformer->fromEvent($data);

        $this->assertArrayHasKey('email', $attributes);
        $this->assertNull($attributes['email']);
        $this->assertSame('Иван', $attributes['name']);
    }

    /** @test */
    public function from_event_maps_gender_string_to_type_id(): void
    {
        $maleAttrs = $this->transformer->fromEvent(['phone' => '+79000000003', 'gender' => 'male']);
        $femaleAttrs = $this->transformer->fromEvent(['phone' => '+79000000004', 'gender' => 'female']);
        $nullAttrs = $this->transformer->fromEvent(['phone' => '+79000000005', 'gender' => null]);

        $this->assertSame(Type::MALE, $maleAttrs['gender_type_id']);
        $this->assertSame(Type::FEMALE, $femaleAttrs['gender_type_id']);
        $this->assertArrayHasKey('gender_type_id', $nullAttrs);
        $this->assertNull($nullAttrs['gender_type_id']);
    }

    /**
     * Главный кейс re-test бага: сохранённые поля не должны зануляться
     * при обновлении через partial RabbitMQ event.
     *
     * @test
     */
    public function partial_event_does_not_overwrite_existing_name_email_with_null(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Александр',
            'surname' => 'Стельмак',
            'email' => 'test@example.com',
            'is_authorized' => true,
        ]);

        // событие содержит ТОЛЬКО id и phone (как при логине по OTP)
        $attributes = $this->transformer->fromEvent([
            'id' => (string) $client->id,
            'phone' => $client->phone,
        ]);

        $client->update($attributes);
        $client->refresh();

        $this->assertSame('Александр', $client->name);
        $this->assertSame('Стельмак', $client->surname);
        $this->assertSame('test@example.com', $client->email);
    }

    /** @test */
    public function find_existing_entity_returns_client_even_when_is_authorized_is_false(): void
    {
        $phone = '+7' . mt_rand(9000000000, 9999999999);
        $client = Client::create([
            'phone' => $phone,
            'name' => 'PendingUser',
            'is_authorized' => false,
        ]);

        $found = $this->transformer->findExistingEntity(['phone' => $phone], []);

        $this->assertNotNull($found, 'findExistingEntity must find clients that are not yet authorized');
        $this->assertSame($client->id, $found->id);
    }

    /** @test */
    public function find_existing_entity_returns_authorized_client_by_phone(): void
    {
        $phone = '+7' . mt_rand(9000000000, 9999999999);
        $client = Client::create([
            'phone' => $phone,
            'name' => 'Authorized',
            'is_authorized' => true,
        ]);

        $found = $this->transformer->findExistingEntity(['phone' => $phone], []);

        $this->assertNotNull($found);
        $this->assertSame($client->id, $found->id);
    }

    /** @test */
    public function find_existing_entity_returns_null_when_phone_missing(): void
    {
        $this->assertNull($this->transformer->findExistingEntity([], []));
        $this->assertNull($this->transformer->findExistingEntity(['phone' => null], []));
    }
}
