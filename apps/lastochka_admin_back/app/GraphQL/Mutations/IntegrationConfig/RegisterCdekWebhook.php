<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\IntegrationConfig;

use Electrohub\DeliveryProviders\Services\SdekDeliveryService;

class RegisterCdekWebhook
{
    public function __invoke($_, array $args): array
    {
        $shopId = (int) $args['shop_id'];
        $url = rtrim((string) config('app.url'), '/') . '/action/webhooks/cdek';

        try {
            $svc = SdekDeliveryService::forShop($shopId);
            $svc->registerWebhook($url);

            return [
                'success' => true,
                'message' => 'Webhook зарегистрирован',
                'url'     => $url,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'url'     => $url,
            ];
        }
    }
}
