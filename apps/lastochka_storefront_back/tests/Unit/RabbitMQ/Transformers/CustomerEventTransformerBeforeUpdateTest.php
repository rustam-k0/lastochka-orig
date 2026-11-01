<?php

declare(strict_types=1);

namespace Tests\Unit\RabbitMQ\Transformers;

use App\Models\User;
use App\RabbitMQ\Transformers\CustomerEventTransformer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class CustomerEventTransformerBeforeUpdateTest extends TestCase
{
    private CustomerEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('rabbitmq-events.source', 'storefront');
        $this->transformer = new CustomerEventTransformer();
    }

    public function test_admin_event_applies_all_fields_even_when_user_filled(): void
    {
        // event пришёл от admin (event_source='app'); payload.source перезаписан
        // ModelEventObserver на 'storefront' через external_source — это и был баг.
        $user = new User([
            'first_name' => 'Александр',
            'last_name' => 'Стельмак',
            'email' => 'old@mail.ru',
        ]);

        $eventData = [
            'event_source' => 'app',
            'source' => 'storefront',
        ];

        $transformed = [
            'first_name' => 'Aleksandr-proverka',
            'last_name' => 'Стельмак',
            'email' => null,
        ];

        $result = $this->transformer->beforeUpdate($user, $eventData, $transformed);

        $this->assertSame($transformed, $result);
    }

    public function test_self_published_echo_does_not_overwrite_filled_fields(): void
    {
        // echo storefront → admin → обратно: event_source='storefront' (своё)
        $user = new User([
            'first_name' => 'Александр',
            'last_name' => 'Стельмак',
            'email' => 'keep@mail.ru',
        ]);

        $eventData = [
            'event_source' => 'storefront',
            'source' => 'storefront',
        ];

        $transformed = [
            'first_name' => 'Other',
            'last_name' => 'Other',
            'email' => 'forced@mail.ru',
            'patronymic' => 'Петрович',
        ];

        $result = $this->transformer->beforeUpdate($user, $eventData, $transformed);

        $this->assertArrayNotHasKey('first_name', $result);
        $this->assertArrayNotHasKey('last_name', $result);
        $this->assertArrayNotHasKey('email', $result);
        $this->assertSame('Петрович', $result['patronymic']);
    }

    public function test_self_published_event_fills_empty_fields(): void
    {
        // self-event может заполнить локально пустые поля
        $user = new User([
            'first_name' => null,
            'last_name' => null,
            'email' => null,
        ]);

        $eventData = [
            'event_source' => 'storefront',
        ];

        $transformed = [
            'first_name' => 'Иван',
            'email' => 'new@mail.ru',
        ];

        $result = $this->transformer->beforeUpdate($user, $eventData, $transformed);

        $this->assertSame('Иван', $result['first_name']);
        $this->assertSame('new@mail.ru', $result['email']);
    }

    public function test_legacy_event_without_event_source_falls_back_to_source(): void
    {
        // старый формат payload без event_source — fallback на source
        $user = new User(['first_name' => 'Александр']);

        $eventData = ['source' => 'admin'];
        $transformed = ['first_name' => 'New'];

        $result = $this->transformer->beforeUpdate($user, $eventData, $transformed);

        $this->assertSame('New', $result['first_name']);
    }
}
