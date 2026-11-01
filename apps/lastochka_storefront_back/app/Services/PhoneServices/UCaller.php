<?php

declare(strict_types=1);

namespace App\Services\PhoneServices;

use App\Contracts\PhoneSystem;
use Illuminate\Support\Facades\Http;

class UCaller implements PhoneSystem
{
    private const string API_URL = 'https://api.ucaller.ru/v1.0/initCall';

    protected string $secretKey;
    protected string $serviceId;

    public function __construct(?array $credentials = null)
    {
        $this->secretKey = $credentials['secret_key'] ?? config('services.ucaller.secret_key', '');
        $this->serviceId = $credentials['service_id'] ?? config('services.ucaller.service_id', '');
    }

    public function sendPhoneConfirmation(string $phone, string $code): mixed
    {
        // uCaller принимает номер в формате 7XXXXXXXXXX (без +)
        $phone = ltrim($phone, '+');

        // uCaller не принимает JSON — только query params или form-urlencoded
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}.{$this->serviceId}",
        ])->asForm()->post(self::API_URL, [
            'phone' => $phone,
            'code' => $code,
        ]);

        return $response->json();
    }
}
