<?php

namespace App\Integrations;

use App\Integrations\Descriptors\CallIntegrationDescriptor;
use App\Integrations\Descriptors\ClientAppIntegrationDescriptor;
use App\Integrations\Descriptors\CourierAppIntegrationDescriptor;
use App\Integrations\Descriptors\DeliveryIntegrationDescriptor;
use App\Integrations\Descriptors\FiscalIntegrationDescriptor;
use App\Integrations\Descriptors\HonestSignIntegrationDescriptor;
use App\Integrations\Descriptors\OneCIntegrationDescriptor;
use App\Integrations\Descriptors\PaymentIntegrationDescriptor;
use App\Integrations\Descriptors\SmsIntegrationDescriptor;

class IntegrationRegistry
{
    protected static ?array $descriptors = null;

    /**
     * @return array<string, IntegrationTypeDescriptor>
     */
    public static function all(): array
    {
        if (static::$descriptors === null) {
            static::$descriptors = [
                'sms' => new SmsIntegrationDescriptor(),
                'call' => new CallIntegrationDescriptor(),
                '1c' => new OneCIntegrationDescriptor(),
                'payment' => new PaymentIntegrationDescriptor(),
                'fiscal' => new FiscalIntegrationDescriptor(),
                'delivery' => new DeliveryIntegrationDescriptor(),
                'delivery_cdek' => new \Electrohub\DeliveryProviders\Integrations\CdekDeliveryDescriptor(),
                'delivery_russian_post' => new \Electrohub\DeliveryProviders\Integrations\RussianPostDeliveryDescriptor(),
                'courier_app' => new CourierAppIntegrationDescriptor(),
                'client_app' => new ClientAppIntegrationDescriptor(),
                'honest_sign' => new HonestSignIntegrationDescriptor(),
            ];
        }

        return static::$descriptors;
    }

    public static function get(string $type): ?IntegrationTypeDescriptor
    {
        return static::all()[$type] ?? null;
    }
}
