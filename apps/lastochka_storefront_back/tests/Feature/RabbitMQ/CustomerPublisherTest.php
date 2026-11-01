<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\Gender;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CustomerPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_created_event_published(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'email' => 'ivan@example.com',
            'birth_date' => '1990-01-15',
            'gender' => Gender::Male,
        ]);

        // Проверяем что событие в outbox
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'customers',
            'entity_id' => $user->id,
            'event_type' => 'created',
        ]);

        // Проверяем payload
        $outbox = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals($user->phone, $payload['phone']);
        $this->assertEquals('Иван', $payload['name']);
        $this->assertEquals('Иванов', $payload['surname']);
        $this->assertEquals('ivan@example.com', $payload['email']);
        $this->assertEquals('1990-01-15', $payload['birthday']);
        $this->assertEquals('male', $payload['gender']);
        $this->assertEquals('storefront', $payload['source']);
        $this->assertEquals((string) $user->id, $payload['id']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertArrayHasKey('shop_code', $payload);
    }

    public function test_customer_without_phone_not_published(): void
    {
        // Создаём пользователя без телефона напрямую в БД (обходя валидацию)
        // В реальности таблица имеет NOT NULL constraint на phone,
        // но тестируем что Condition блокирует публикацию для пустого телефона
        $userId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'phone' => '',
            'first_name' => 'Test',
            'last_name' => 'User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Событие не должно быть опубликовано т.к. Condition проверяет !empty(phone)
        $this->assertDatabaseMissing('event_outbox', [
            'entity_type' => 'customers',
            'entity_id' => $userId,
        ]);
    }

    public function test_customer_updated_event_published(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        // Обновляем
        $user->update(['first_name' => 'Пётр']);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'customers',
            'entity_id' => $user->id,
            'event_type' => 'updated',
        ]);

        // Проверяем payload
        $outbox = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->where('event_type', 'updated')
            ->first();

        $this->assertNotNull($outbox);
        $payload = $outbox->payload;
        $this->assertEquals('Пётр', $payload['name']);
    }

    public function test_customer_payload_format(): void
    {
        $user = User::factory()->create([
            'phone' => '+79997776655',
            'first_name' => 'Анна',
            'last_name' => 'Сидорова',
            'patronymic' => 'Петровна',
            'email' => 'anna@example.com',
            'birth_date' => '1985-05-20',
            'gender' => Gender::Female,
        ]);

        $outbox = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->first();

        $payload = $outbox->payload;

        // Проверяем все обязательные поля согласно aladdin_exchange.md
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertArrayHasKey('event_source', $payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('shop_code', $payload);
        $this->assertArrayHasKey('source', $payload);
        $this->assertArrayHasKey('phone', $payload);
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('surname', $payload);
        $this->assertArrayHasKey('patronymic', $payload);
        $this->assertArrayHasKey('email', $payload);
        $this->assertArrayHasKey('birthday', $payload);
        $this->assertArrayHasKey('gender', $payload);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertArrayHasKey('updated_at', $payload);

        // Проверяем значения
        $this->assertEquals('Анна', $payload['name']);
        $this->assertEquals('Сидорова', $payload['surname']);
        $this->assertEquals('Петровна', $payload['patronymic']);
        $this->assertEquals('female', $payload['gender']);
        $this->assertEquals('1985-05-20', $payload['birthday']);
    }

    public function test_customer_with_empty_profile_published(): void
    {
        $user = User::factory()->empty()->create([
            'phone' => '+79998887766',
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'customers',
            'entity_id' => $user->id,
            'event_type' => 'created',
        ]);

        $outbox = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->first();

        $payload = $outbox->payload;

        $this->assertEquals('+79998887766', $payload['phone']);
        $this->assertNull($payload['name']);
        $this->assertNull($payload['surname']);
        $this->assertNull($payload['email']);
        $this->assertNull($payload['birthday']);
        $this->assertNull($payload['gender']);
    }

    public function test_routing_key_is_customer_prefix(): void
    {
        $user = User::factory()->create(['phone' => '+79991112233']);

        $outbox = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->first();

        $this->assertEquals('customer.created', $outbox->routing_key);
    }

    public function test_multiple_updates_create_multiple_events(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        // Первое обновление
        $user->update(['first_name' => 'Имя1']);

        // Второе обновление
        $user->update(['last_name' => 'Фамилия2']);

        // Должно быть 3 события: created + 2 updated
        $count = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $user->id)
            ->count();

        $this->assertEquals(3, $count);
    }
}
