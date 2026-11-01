<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\ClientDeliveryInfo;
use App\Models\DeliveryAddress;
use App\Models\Type;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClientAddressEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'id',
        'client_id',
        'client_source',
        'address',
    ];

    /**
     * External references configuration
     */
    protected array $externalReferences = [
        'client_id' => [
            'type' => Client::class,
            'source_field' => 'client_source',
            'id_field' => 'client_id',
            'nullable' => false,
        ],
    ];

    /**
     * Convert DeliveryAddress to event payload
     */
    public function toEvent(Model $address, ?object $relatedModel = null, ?string $eventId = null): array
    {
        /** @var DeliveryAddress $address */

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($address->deleted_at ?? $address->updated_at ?? $address->created_at),
            'id' => (string) $address->id,
            'shop_code' => config('rabbitmq-events.shop_code'),
            'source' => config('rabbitmq-events.source'),
            'address' => $address->address,
            'entrance' => $address->entrance,
            'intercom' => $address->intercom,
            'floor' => $address->floor,
            'flat' => $address->flat,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'title' => $address->title,
            'active' => true,
            'created_at' => $address->created_at,
            'updated_at' => $address->updated_at,
            'deleted_at' => $address->deleted_at,
        ];

        // Inject client_id dynamically for the trait to find it
        // Assuming the relationship is via ClientDeliveryInfo
        if ($address->deliveryInfo) {
             $address->client_id = $address->deliveryInfo->client_id;
        }

        // Add external references (client_id + client_source)
        return $this->addExternalReferencesToEvent($address, $eventData);
    }

    /**
     * Convert event payload to DeliveryAddress attributes
     */
    public function fromEvent(array $data): array
    {
        return [
            'address' => $data['address'] ?? null,
            'entrance' => $data['entrance'] ?? null,
            'intercom' => $data['intercom'] ?? null,
            'floor' => $data['floor'] ?? null,
            'flat' => $data['flat'] ?? null,
            'latitude' => $data['latitude'] ?? 0,
            'longitude' => $data['longitude'] ?? 0,
            'title' => $data['title'] ?? 'No address',
        ];
    }

    /**
     * Called BEFORE creating a new entity
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        // Resolve client_id before creation to get delivery_info_id
        // We use resolveExternalReference directly to avoid creating pending references for unsaved model
        $config = $this->externalReferences['client_id'] ?? null;

        if ($config && isset($eventData[$config['source_field']], $eventData[$config['id_field']])) {
            $source = $eventData[$config['source_field']];
            $externalId = $eventData[$config['id_field']];
            $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

            $clientId = $this->resolveExternalReference($config['type'], $source, $externalId, $shopCode);

            if ($clientId) {
                $deliveryInfo = ClientDeliveryInfo::firstOrCreate([
                    'client_id' => $clientId,
                    'type_id' => defined(Type::class . '::DELIVERY_COURIER') ? Type::DELIVERY_COURIER : 2, // 2 = Courier
                ], [
                    'shop_id' => 1,
                ]);

                $transformedData['delivery_info_id'] = $deliveryInfo->id;
            } else {
                // Client not found - skip address creation, pending reference will be created
                \Log::warning('DeliveryAddress creation skipped: client not found', [
                    'client_source' => $source,
                    'client_external_id' => $externalId,
                    'shop_code' => $shopCode,
                ]);
                return null;
            }
        } else {
            // Required client reference data missing - cannot create address
            \Log::warning('DeliveryAddress creation skipped: missing client reference data', [
                'event_data_keys' => array_keys($eventData),
            ]);
            return null;
        }

        return $transformedData;
    }

    /**
     * After create: Resolve client_id and link address
     */
    public function afterCreate(Model $address, array $eventData): void
    {
        $this->resolveAndLinkClient($address, $eventData);
    }

    /**
     * After update: Resolve client_id and link address
     */
    public function afterUpdate(Model $address, array $eventData): void
    {
        $this->resolveAndLinkClient($address, $eventData);
    }

    /**
     * Custom resolution logic because client_id is not on the model directly
     */
    protected function resolveAndLinkClient(Model $address, array $eventData): void
    {
        // 1. Resolve references using the trait
        // This returns an array of ['client_id' => 123] if resolved
        $resolved = $this->processExternalReferences($eventData, $address, []);

        if (empty($resolved['client_id'])) {
            return;
        }

        $clientId = $resolved['client_id'];

        // 2. Find or create ClientDeliveryInfo
        // We use a default type (e.g. Courier) and default shop
        // Assuming Type::DELIVERY_COURIER is available and correct.
        // If Type model is not available in the package context, we might need to use raw ID or verify logic.
        // However, Type is App\Models\Type.

        // Assuming default shop_id = 1
        $shopId = 1;

        $deliveryInfo = ClientDeliveryInfo::firstOrCreate([
            'client_id' => $clientId,
            'type_id' => defined(Type::class . '::DELIVERY_COURIER') ? Type::DELIVERY_COURIER : 1, // Fallback or check constant
        ], [
            'shop_id' => $shopId,
        ]);

        // 3. Link address to delivery info
        if ($address->delivery_info_id !== $deliveryInfo->id) {
            $address->withoutEvents(function () use ($address, $deliveryInfo) {
                $address->forceFill(['delivery_info_id' => $deliveryInfo->id])->save();
            });
        }
    }
}
