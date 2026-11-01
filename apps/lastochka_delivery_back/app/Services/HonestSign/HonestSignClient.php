<?php

namespace App\Services\HonestSign;

use App\Models\IntegrationConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Проверка кода маркировки в 1С Розница (ручка CheckCIS, MID-266).
 *
 * Контракт 1С: POST {url}, Basic Auth, JSON {"barcode","cis"} →
 * 200 {"success": bool, "message": string}. Креды — интеграция
 * «Честный знак» (integration_type=honest_sign, драйвер onec_check_cis)
 * в общей таблице integration_configs, настраивается в админке.
 *
 * Формат отказа 1С пока не подтверждён, поэтому разбор консервативный:
 * разрешение = ровно `success === true`, всё остальное при живом ответе —
 * отказ с текстом 1С.
 */
class HonestSignClient
{
  public const INTEGRATION_TYPE = 'honest_sign';

  public const DRIVER_ONEC_CHECK_CIS = 'onec_check_cis';

  public const DEFAULT_REJECT_MESSAGE = 'Код маркировки не прошёл проверку';

  private const TIMEOUT_SECONDS = 10;

  public function isConfigured(): bool
  {
    return $this->credentials() !== null;
  }

  public function check(string $barcode, string $cis): HonestSignCheckResult
  {
    $credentials = $this->credentials();

    if ($credentials === null) {
      Log::warning('HonestSign: проверка кода без настроенной интеграции', ['barcode' => $barcode]);

      return HonestSignCheckResult::unavailable('Проверка маркировки не настроена (интеграция «Честный знак»)');
    }

    try {
      $response = Http::withBasicAuth($credentials['login'], $credentials['password'])
        ->asJson()
        ->acceptJson()
        ->timeout(self::TIMEOUT_SECONDS)
        ->post($credentials['url'], [
          'barcode' => $barcode,
          'cis' => $cis,
        ]);
    } catch (ConnectionException $e) {
      Log::error('HonestSign: 1С недоступна', ['barcode' => $barcode, 'error' => $e->getMessage()]);

      return HonestSignCheckResult::unavailable('1С не отвечает: ' . $e->getMessage());
    } catch (\Throwable $e) {
      Log::error('HonestSign: ошибка запроса к 1С', ['barcode' => $barcode, 'error' => $e->getMessage()]);

      return HonestSignCheckResult::unavailable($e->getMessage());
    }

    $status = $response->status();
    $body = $response->json();
    $text = mb_substr(trim((string) $response->body()), 0, 255);

    Log::info('HonestSign: ответ CheckCIS', [
      'barcode' => $barcode,
      'cis' => $cis,
      'status' => $status,
      'body' => $text,
    ]);

    if ($status === 200) {
      if (!is_array($body)) {
        return HonestSignCheckResult::unavailable('Неожиданный ответ 1С: ' . $text);
      }

      $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : null;

      if (($body['success'] ?? null) === true) {
        return HonestSignCheckResult::allowed($message);
      }

      return HonestSignCheckResult::rejected($message !== null && $message !== '' ? $message : self::DEFAULT_REJECT_MESSAGE);
    }

    // Отказ по существу: 1С отклонила сам запрос (валидация, конфликт).
    if (in_array($status, [400, 409, 422], true)) {
      $message = is_array($body) && isset($body['message']) && is_string($body['message'])
        ? trim($body['message'])
        : $text;

      return HonestSignCheckResult::rejected($message !== '' ? $message : self::DEFAULT_REJECT_MESSAGE);
    }

    // 401/403 (креды), 404/405 (URL), 5xx — проблема интеграции, не кода.
    return HonestSignCheckResult::unavailable("1С вернула HTTP {$status}");
  }

  /**
   * @return array{url: string, login: string, password: string}|null
   */
  protected function credentials(): ?array
  {
    $config = IntegrationConfig::query()
      ->where('integration_type', self::INTEGRATION_TYPE)
      ->where('is_active', true)
      ->first();

    if (!$config) {
      return null;
    }

    try {
      $all = $config->credentials ?? [];
    } catch (\Throwable $e) {
      Log::error('HonestSign: не удалось расшифровать credentials (APP_KEY отличается от admin_back?)', ['error' => $e->getMessage()]);

      return null;
    }

    $driver = $all[self::DRIVER_ONEC_CHECK_CIS] ?? [];
    $url = trim((string) ($driver['url'] ?? ''));
    $login = (string) ($driver['login'] ?? '');
    $password = (string) ($driver['password'] ?? '');

    if ($url === '' || $login === '' || $password === '') {
      return null;
    }

    return ['url' => $url, 'login' => $login, 'password' => $password];
  }
}
