<?php

namespace App\Services\PhoneServices;

use App\Services\Sms\SmsTextBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsAero implements \App\Contracts\PhoneSystem
{
  const API_URL = "https://gate.smsaero.ru/v2/sms/send";

  protected string $login;
  protected string $password;
  protected string $sign;

  public function __construct(?array $credentials = null)
  {
    $this->login = $credentials['login'] ?? config("services.smsaero.login") ?? '';
    $this->password = $credentials['api_key'] ?? config("services.smsaero.password") ?? '';
    $this->sign = ($credentials['sign'] ?? null) ?: config("services.smsaero.sign", "SMS Aero");
  }

  public function sendPhoneConfirmation(string $phone, string $code): mixed
  {
    $text = SmsTextBuilder::confirmationCode($code);

    try {
      $response = Http::timeout(10)
        ->withBasicAuth($this->login, $this->password)
        ->get(self::API_URL, [
          'number' => $phone,
          'text' => $text,
          'sign' => $this->sign,
        ]);

      if (! $response->successful() || $response->json('success') === false) {
        Log::warning('SmsAero: отправка SMS не удалась', [
          'phone' => $phone,
          'status' => $response->status(),
          'message' => $response->json('message'),
        ]);
      }
    } catch (\Throwable $e) {
      Log::warning('SmsAero: ошибка при отправке SMS: ' . $e->getMessage(), [
        'phone' => $phone,
      ]);

      return null;
    }

    return $response->json();
  }
}
