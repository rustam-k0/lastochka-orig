<?php

namespace App\Services;

use App\Contracts\SmsService;
use App\Models\IntegrationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsAero implements SmsService
{
  private const API_URL = 'https://gate.smsaero.ru/v2/sms/send';

  private const DRIVER = 'smsaero';

  protected ?string $login;
  protected ?string $api_key;
  protected string $sign;

  /**
   * Credentials берутся из интеграции «SMS-рассылка» (админка →
   * Интеграции), с fallback на .env. Раньше читался только .env,
   * из-за чего настроенная в админке интеграция не использовалась.
   *
   * @param array<string, mixed>|null $credentials
   */
  public function __construct(?array $credentials = null)
  {
    $credentials ??= $this->resolveCredentials();

    $this->login = $credentials['login'] ?? config('services.smsaero.email');
    $this->api_key = $credentials['api_key'] ?? config('services.smsaero.api_key');
    $this->sign = ($credentials['sign'] ?? null) ?: config('services.smsaero.sign', 'SMS Aero');
  }

  public function send(string $message, string $phone): void
  {
    if (blank($this->login) || blank($this->api_key)) {
      Log::warning('SmsAero: не заданы логин или API-ключ — SMS не отправлена', [
        'phone' => $phone,
      ]);

      return;
    }

    try {
      $response = Http::timeout(10)
        ->withBasicAuth($this->login, $this->api_key)
        ->get(self::API_URL, [
          'number' => $phone,
          'text' => $message,
          'sign' => $this->sign,
        ]);

      if (!$response->successful() || $response->json('success') === false) {
        Log::warning('SmsAero: отправка SMS не удалась', [
          'phone' => $phone,
          'status' => $response->status(),
          'message' => $response->json('message'),
        ]);
      }
    } catch (Throwable $e) {
      Log::warning('SmsAero: ошибка при отправке SMS: ' . $e->getMessage(), [
        'phone' => $phone,
      ]);
    }
  }

  /**
   * Активная интеграция типа `sms` с драйвером smsaero.
   * Credentials в admin_back хранятся сгруппированно по драйверу.
   *
   * @return array<string, mixed>
   */
  private function resolveCredentials(): array
  {
    try {
      $config = IntegrationConfig::query()
        ->where('integration_type', 'sms')
        ->where('is_active', true)
        ->first();

      if (!$config || $config->driver !== self::DRIVER) {
        return [];
      }

      $credentials = $config->credentials ?? [];

      return $credentials[self::DRIVER] ?? $credentials;
    } catch (Throwable $e) {
      Log::warning('SmsAero: не удалось прочитать настройки интеграции: ' . $e->getMessage());

      return [];
    }
  }
}
