<?php

declare(strict_types=1);

namespace App\Transformers;

use App\Models\Role;
use App\Models\User;
use App\Services\ModuleConfigService;
use App\Services\OneC\OneCEmployeeShopsSync;
use App\Services\OneC\OneCRoleResolver;
use App\Support\EmployeePhone;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Сотрудники из 1С: employee.created / employee.updated (MID-306).
 *
 * Работает только для события 1С при модуле «1С ведёт магазины и сотрудников».
 * Поиск: GUID (метаданные пакета) → телефон (сотрудник, заведённый вручную).
 * Пароль из 1С не приходит: при создании случайный, SMS не отправляется.
 * Удаление и увольнение не обрабатываются; удалённый в админке сотрудник не обновляется.
 * Роли — только белый список config('onec.employee_roles'): пользователь с ролью вне списка
 * (например, администратор) не связывается по телефону и не обновляется.
 */
class EmployeeEventTransformer implements EventTransformer
{
  use ValidatesEventData {
    validate as protected validateEventData;
  }
  use HasEventHandlerHooks;

  protected array $requiredFields = ['event_id', 'id', 'source'];

  public function __construct(
    private readonly OneCRoleResolver $roles,
    private readonly OneCEmployeeShopsSync $shopsSync,
  ) {
  }

  /**
   * Увольнение/удаление из 1С не обрабатываются (вне спеки): нестандартный
   * event_type (в т.ч. deleted) отбрасывается ДО создания event_sync_metadata,
   * иначе следующее нормальное событие для того же GUID попадёт на orphan
   * metadata и создаст сотрудника в обход beforeCreate (без пароля).
   */
  public function validate(array $data): bool
  {
    $eventType = $data['event_type'] ?? 'updated';

    if (!in_array($eventType, ['created', 'updated'], true)) {
      Log::info('1C employee event skipped: unsupported event_type', [
        'event_id' => $data['event_id'] ?? null,
        'external_id' => $data['id'] ?? null,
        'event_type' => $eventType,
      ]);

      return false;
    }

    return $this->validateEventData($data);
  }

  public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
  {
    return [];
  }

  public function fromEvent(array $data): array
  {
    $attributes = $this->nameAttributes($data);

    if (array_key_exists('phone', $data)) {
      $attributes['phone'] = EmployeePhone::normalize($data['phone']);
    }

    if (array_key_exists('email', $data)) {
      $email = $this->text($data['email']);

      if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        $this->warn('email is invalid, not changed', $data);
      } else {
        $attributes['email'] = $email;
      }
    }

    return $attributes;
  }

  public function findExistingEntity(array $eventData, array $transformedData): ?Model
  {
    if (!$this->isOneCEmployeeEvent($eventData) || empty($transformedData['phone'])) {
      return null;
    }

    $user = User::query()->where('phone', $transformedData['phone'])->first();

    if ($user === null) {
      return null;
    }

    // Администратор и прочие роли вне белого списка 1С не привязываются: иначе 1С
    // перезаписала бы им почту и телефон (beforeCreate отклонит создание как «телефон занят»).
    if ($this->hasRoleOutsideWhitelist($user)) {
      $this->warn('phone belongs to user with role outside 1C whitelist', $eventData, ['user_id' => $user->getKey()]);

      return null;
    }

    // Телефон уже у другого сотрудника из 1С — не перепривязываем (beforeCreate отклонит).
    $linkedToOtherGuid = EventSyncMetadata::query()
      ->where('entity_type', 'employees')
      ->where('source', '1c')
      ->where('entity_id', $user->id)
      ->where('external_id', '!=', (string) $eventData['id'])
      ->exists();

    return $linkedToOtherGuid ? null : $user;
  }

  public function beforeCreate(array $eventData, array $transformedData): ?array
  {
    if (!$this->isOneCEmployeeEvent($eventData)) {
      Log::info('1C employee event skipped: module onec_shops_master is off or source is not 1C', [
        'event_id' => $eventData['event_id'] ?? null,
        'external_id' => $eventData['id'] ?? null,
      ]);

      return null;
    }

    $missing = array_values(array_filter(
      ['surname', 'name', 'phone'],
      static fn (string $key) => empty($transformedData[$key])
    ));

    if ($missing !== []) {
      $this->warn('employee not created: required fields are empty or invalid', $eventData, ['fields' => $missing]);

      return null;
    }

    if (User::query()->where('phone', $transformedData['phone'])->exists()) {
      $this->warn('employee not created: phone belongs to another user', $eventData);

      return null;
    }

    if ($this->roles->resolve($eventData['role'] ?? null) === null) {
      $this->warn('employee not created: role not found', $eventData, ['role' => $eventData['role'] ?? null]);

      return null;
    }

    if (!empty($transformedData['email']) && $this->emailTaken($transformedData['email'], null)) {
      unset($transformedData['email']);
      $this->warn('email belongs to another employee, not set', $eventData);
    }

    // Создание идёт внутри withoutEvents: хук User::boot() creating не срабатывает.
    // Одноразовый случайный пароль (вход после смены админом): стойкость хеша не нужна,
    // дешёвый bcrypt не тормозит пачку сотрудников.
    $transformedData['password'] = Hash::make(Str::random(40), ['rounds' => 4]);
    $transformedData['external_user_id'] = sha1(Str::random(300));

    return $transformedData;
  }

  public function beforeUpdate(Model $user, array $eventData, array $transformedData): ?array
  {
    if (!$this->isOneCEmployeeEvent($eventData)) {
      return null;
    }

    if (method_exists($user, 'trashed') && $user->trashed()) {
      Log::info('1C employee event skipped: employee deleted in admin', [
        'event_id' => $eventData['event_id'] ?? null,
        'user_id' => $user->getKey(),
      ]);

      return null;
    }

    if ($this->hasRoleOutsideWhitelist($user)) {
      $this->warn('employee not updated: user has role outside 1C whitelist', $eventData, ['user_id' => $user->getKey()]);

      return null;
    }

    foreach (['surname', 'name'] as $key) {
      if (array_key_exists($key, $transformedData) && empty($transformedData[$key])) {
        unset($transformedData[$key]);
        $this->warn("{$key} is empty, not changed", $eventData);
      }
    }

    if (array_key_exists('phone', $transformedData)) {
      $phone = $transformedData['phone'];

      if ($phone === null) {
        unset($transformedData['phone']);
        $this->warn('phone is invalid, not changed', $eventData);
      } elseif (User::query()->where('phone', $phone)->whereKeyNot($user->getKey())->exists()) {
        unset($transformedData['phone']);
        $this->warn('phone belongs to another employee, not changed', $eventData);
      }
    }

    if (!empty($transformedData['email']) && $this->emailTaken($transformedData['email'], (int) $user->getKey())) {
      unset($transformedData['email']);
      $this->warn('email belongs to another employee, not changed', $eventData);
    }

    return $transformedData;
  }

  public function afterCreate(Model $user, array $eventData): void
  {
    $this->syncRoleAndShops($user, $eventData);
  }

  public function afterUpdate(Model $user, array $eventData): void
  {
    $this->syncRoleAndShops($user, $eventData);
  }

  /**
   * Увольнение/удаление из 1С не обрабатываются (вне спеки). validate() уже
   * отбрасывает event_type=deleted до создания metadata; этот хук — вторая
   * линия защиты, если delete-путь будет достигнут другим способом
   * (например, orphan-восстановление в консьюмере).
   */
  public function beforeDelete(Model $model, array $eventData): bool
  {
    Log::info('1C employee event skipped: delete or dismissal not supported', [
      'event_id' => $eventData['event_id'] ?? null,
      'user_id' => $model->getKey(),
    ]);

    return false;
  }

  /**
   * Событие 1С (source и event_source = 1c) при модуле «1С ведёт магазины и сотрудников».
   */
  public function isOneCEmployeeEvent(array $eventData): bool
  {
    $source = $eventData['source'] ?? null;

    return $source === '1c'
      && ($eventData['event_source'] ?? $source) === '1c'
      && ModuleConfigService::isOneCShopsMasterEnabled();
  }

  /**
   * Магазины и графики сотрудника: ключа shops нет — не трогаем; null — отвязать все;
   * не массив — не трогаем, warning.
   */
  protected function syncShops(User $user, array $eventData): void
  {
    if (!array_key_exists('shops', $eventData)) {
      return;
    }

    $shops = $eventData['shops'];

    if ($shops !== null && !is_array($shops)) {
      $this->warn('shops is not an array, bindings not changed', $eventData);

      return;
    }

    $this->shopsSync->sync($user, $shops ?? []);
  }

  private function syncRoleAndShops(Model $user, array $eventData): void
  {
    if (array_key_exists('role', $eventData)) {
      $role = $this->roles->resolve($eventData['role']);

      if ($role !== null) {
        // syncRoles только при расхождении: повторный обмен с той же ролью не переписывает
        // model_has_roles (detach + insert на каждого сотрудника пачки).
        $currentRoleIds = $user->roles()->pluck('roles.id')->map(static fn ($id) => (int) $id)->all();

        if ($currentRoleIds !== [(int) $role->getKey()]) {
          $user->syncRoles([$role]);
        }
      } else {
        $this->warn('role not found, not changed', $eventData, ['role' => $eventData['role']]);
      }
    }

    $this->syncShops($user, $eventData);

    if ($user->hasAnyRole([Role::COLLECTOR, Role::COURIER]) && $user->shops()->doesntExist()) {
      $this->warn('collector or courier has no shops', $eventData, ['user_id' => $user->getKey()]);
    }
  }

  /**
   * ФИО: отдельные ключи (только присланные) или full_name «Фамилия Имя Отчество»
   * (Спека 6.2: full_name используется, только если нет surname и name).
   * Присланный patronymic имеет приоритет над остатком, разобранным из full_name.
   */
  private function nameAttributes(array $data): array
  {
    $hasSplitName = array_key_exists('surname', $data) || array_key_exists('name', $data);

    if (!$hasSplitName && array_key_exists('full_name', $data)) {
      $parts = preg_split('/\s+/u', (string) $this->text($data['full_name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

      $attributes = [
        'surname' => $parts[0] ?? null,
        'name' => $parts[1] ?? null,
        'patronymic' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null,
      ];

      if (array_key_exists('patronymic', $data)) {
        $attributes['patronymic'] = $this->text($data['patronymic']);
      }

      return $attributes;
    }

    $attributes = [];

    foreach (['surname', 'name', 'patronymic'] as $key) {
      if (array_key_exists($key, $data)) {
        $attributes[$key] = $this->text($data[$key]);
      }
    }

    return $attributes;
  }

  /**
   * У пользователя есть роль вне белого списка 1С (admin, superadmin, …).
   */
  private function hasRoleOutsideWhitelist(Model $user): bool
  {
    return $user->roles()
      ->pluck('name')
      ->contains(fn ($name) => !$this->roles->isAllowedRoleName((string) $name));
  }

  private function emailTaken(string $email, ?int $exceptUserId): bool
  {
    return User::query()
      ->where('email', $email)
      ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
      ->exists();
  }

  /**
   * Строка из события 1С: trim, пустая → null, до 255 символов; не строка → null.
   */
  private function text(mixed $value): ?string
  {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
      return null;
    }

    $text = trim((string) $value);

    return $text === '' ? null : mb_substr($text, 0, 255);
  }

  private function warn(string $message, array $eventData, array $context = []): void
  {
    Log::warning('1C employee event: ' . $message, array_merge([
      'event_id' => $eventData['event_id'] ?? null,
      'external_id' => $eventData['id'] ?? null,
    ], $context));
  }
}
