<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\ClientDeliveryInfo;
use App\Models\Type;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

use Comrades\RabbitMQEvents\Models\EventSyncMetadata;

class ClientEventTransformer implements EventTransformer
{
  use ValidatesEventData;
  use HasEventHandlerHooks;
  use ResolvesShopCode;

  protected array $requiredFields = [
    'event_id',
    'id',
    'phone',
  ];

  /**
   * Convert Client to event payload
   */
  public function toEvent(Model $client, ?object $relatedModel = null, ?string $eventId = null): array
  {
    // Map gender int -> string
    $gender = match ($client->gender_type_id) {
      Type::MALE => 'male',
      Type::FEMALE => 'female',
      default => null,
    };

    /** @var Client $client */
    return [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => ($client->deleted_at ?? $client->updated_at ?? $client->created_at),
      'id' => (string) $client->id,
      'shop_code' => $this->resolveShopCode($relatedModel, $client),
      'source' => config('rabbitmq-events.source'),
      'name' => $client->name,
      'surname' => $client->surname,
      'patronymic' => $client->patronymic,
      'phone' => $client->phone,
      'email' => $client->email,
      'birthday' => $client->birthday,
      'gender' => $gender,
      'loyalty_id' => $client->loyalty_id,
      'card_number' => $client->card_number,
      'created_at' => $client->created_at,
      'updated_at' => $client->updated_at,
      'deleted_at' => $client->deleted_at,
    ];
  }

  /**
   * Convert event payload to Client attributes.
   *
   * Передаются ТОЛЬКО те ключи, что реально присутствуют в payload'е события.
   * Partial event (например логин по OTP без заполненного профиля) не должен
   * затирать существующие name/email/birthday в admin_back.
   *
   * bonus_balance/bonus_reserved — зеркало баланса из storefront. Единственный
   * путь записи этих полей в админке: без ключа в payload зеркало не трогаем.
   */
  public function fromEvent(array $data): array
  {
    $attributes = [
      'is_authorized' => true,
    ];

    foreach (['name', 'surname', 'patronymic', 'phone', 'email', 'birthday', 'loyalty_id', 'card_number', 'bonus_balance', 'bonus_reserved'] as $key) {
      if (array_key_exists($key, $data)) {
        $attributes[$key] = $data[$key];
      }
    }

    if (array_key_exists('gender', $data)) {
      $attributes['gender_type_id'] = match ($data['gender']) {
        'male' => Type::MALE,
        'female' => Type::FEMALE,
        default => null,
      };
    }

    return $attributes;
  }

  /**
   * Дедупликация: поиск существующего клиента по телефону.
   *
   * withoutGlobalScopes() — чтобы не зависеть от scope `onlyAuthorized` (иначе
   * новый клиент в промежуточном состоянии is_authorized=0 не найдётся и породит дубль).
   *
   * lockForUpdate() — защита от race condition между параллельными консюмерами:
   * два события client.updated приходят в пределах секунды и каждое делает INSERT.
   */
  public function findExistingEntity(array $eventData, array $transformedData): ?Model
  {
    $phone = $eventData['phone'] ?? null;

    if (!$phone) {
      return null;
    }

    return Client::withoutGlobalScopes()
      ->where('phone', $phone)
      ->lockForUpdate()
      ->first();
  }

  /**
   * Hook: Before creating a new client
   */
  public function beforeCreate(array $eventData, array $transformedData): ?array
  {
    // Generate random password since model events are disabled
    if (!isset($transformedData['password'])) {
      $transformedData['password'] = bcrypt(Str::random());
    }

    return $transformedData;
  }

  /**
   * After creating a client, ensure they have a ClientDeliveryInfo
   */
  public function afterCreate(Model $client, array $eventData): void
  {
    // Ensure ClientDeliveryInfo exists for this client
    ClientDeliveryInfo::firstOrCreate([
      'client_id' => $client->id,
      'type_id' => Type::DELIVERY_COURIER, // Default type
    ], [
      'shop_id' => $this->resolveShopIdSafe($eventData['shop_code'] ?? config('rabbitmq-events.shop_code')),
    ]);
  }
}
