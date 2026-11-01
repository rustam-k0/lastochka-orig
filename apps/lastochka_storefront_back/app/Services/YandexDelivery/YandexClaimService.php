<?php

namespace App\Services\YandexDelivery;

use App\Enums\YandexClaimStatus;
use App\Models\Order;
use App\Models\YandexDeliveryClaim;
use App\Services\IntegrationConfigService;
use Illuminate\Support\Facades\Log;

class YandexClaimService
{
    public function __construct(
        protected YandexGoClientInterface $client,
    ) {}

    public function createAndAcceptClaim(Order $order, YandexDeliveryClaim $claim): void
    {
        $payload = $this->buildClaimPayload($order, $claim);
        $requestId = $order->idempotency_key ?? (string) $order->id;

        $createResponse = $this->client->createClaim($payload, $requestId);

        $claimId = $createResponse['id'];
        $version = $createResponse['version'] ?? 1;
        $status = $createResponse['status'] ?? 'new';

        $claim->update([
            'claim_id' => $claimId,
            'status' => $status,
            'raw_response' => $createResponse,
        ]);

        if ($status === 'ready_for_approval' || !empty($createResponse['pricing']['offer'])) {
            $this->acceptClaimInternal($claim, $claimId, $version, $createResponse, $order);
            return;
        }

        $this->waitForApprovalAndAccept($claim, $claimId, $order);
    }

    public function cancelClaim(YandexDeliveryClaim $claim): void
    {
        if (!$claim->claim_id) {
            $claim->update(['status' => YandexClaimStatus::Cancelled]);
            return;
        }

        try {
            $info = $this->client->getClaimInfo($claim->claim_id);
            $version = $info['version'] ?? 1;
            $cancelState = $claim->status->isPickupArrivedOrLater() ? 'paid' : 'free';
            $this->client->cancelClaim($claim->claim_id, $cancelState, $version);
            $claim->update(['status' => YandexClaimStatus::Cancelled]);
        } catch (\Throwable $e) {
            Log::error('Failed to cancel Yandex claim', [
                'claim_id' => $claim->claim_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyClaim(string $claimId): array
    {
        return $this->client->getClaimInfo($claimId);
    }

    protected function waitForApprovalAndAccept(
        YandexDeliveryClaim $claim,
        string $claimId,
        Order $order,
    ): void {
        $maxAttempts = 6;
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(5);
            $info = $this->client->getClaimInfo($claimId);
            $status = $info['status'] ?? '';

            if ($status === 'ready_for_approval' && !empty($info['pricing']['offer'])) {
                $version = $info['version'] ?? 1;
                $this->acceptClaimInternal($claim, $claimId, $version, $info, $order);
                return;
            }

            if (in_array($status, ['estimating_failed', 'cancelled', 'failed'])) {
                $claim->update(['status' => $status]);
                throw new \RuntimeException("Yandex claim failed with status: {$status}");
            }
        }

        throw new \RuntimeException('Yandex claim did not reach ready_for_approval in time');
    }

    protected function acceptClaimInternal(
        YandexDeliveryClaim $claim,
        string $claimId,
        int $version,
        array $claimData,
        Order $order,
    ): void {
        $acceptResponse = $this->client->acceptClaim($claimId, $version);

        $offerPrice = (float) ($claimData['pricing']['offer']['price'] ?? 0);
        $priceRounded = (int) ceil($offerPrice);

        $claim->update([
            'status' => YandexClaimStatus::Accepted,
            'price' => $priceRounded,
            'raw_response' => $acceptResponse,
        ]);

        // Бесплатная доставка (delivery_cost=0 по порогу freeDeliveryFrom): клиент за
        // доставку не платит, поэтому реальную offer-цену Яндекса в delivery_cost клиента
        // НЕ записываем — её покрывает магазин (claim.price выше = фактическая стоимость).
        // Для платной доставки синхронизируем delivery_cost с фактической offer-ценой
        // Яндекса (она может отличаться от предварительной checkPrice). ALADDIN-11465.
        if ($order->delivery_cost > 0 && $priceRounded !== $order->delivery_cost) {
            $diff = $priceRounded - $order->delivery_cost;
            $order->update([
                'delivery_cost' => $priceRounded,
                'total' => $order->total + $diff,
            ]);
        }
    }

    protected function buildClaimPayload(Order $order, YandexDeliveryClaim $claim): array
    {
        $order->load(['store', 'address', 'user']);
        $store = $order->store;
        $orderAddress = $order->address;
        $emergencyPhone = $this->resolveEmergencyPhone();

        return [
            'emergency_contact' => [
                'name' => $store->title ?? 'Магазин',
                'phone' => $emergencyPhone,
            ],
            'items' => [[
                'title' => "Заказ №{$order->id}",
                'quantity' => 1,
                'size' => config('yandex-delivery.default_item_size'),
                'weight' => config('yandex-delivery.default_item_weight', 5),
                'cost_value' => number_format($order->subtotal, 2, '.', ''),
                'cost_currency' => 'RUB',
                'pickup_point' => 1,
                'droppof_point' => 2,
            ]],
            'route_points' => [
                [
                    'point_id' => 1,
                    'visit_order' => 1,
                    'type' => 'source',
                    'address' => [
                        'fullname' => $store->address ?? $store->title ?? 'Магазин',
                        'coordinates' => [(float) $store->longitude, (float) $store->latitude],
                    ],
                    'contact' => [
                        'name' => $store->title ?? 'Магазин',
                        'phone' => $emergencyPhone,
                    ],
                    'skip_confirmation' => true,
                ],
                [
                    'point_id' => 2,
                    'visit_order' => 2,
                    'type' => 'destination',
                    'address' => [
                        'fullname' => $orderAddress->full_address,
                        'coordinates' => [(float) $orderAddress->longitude, (float) $orderAddress->latitude],
                    ],
                    'contact' => [
                        'name' => $order->user->name ?? 'Покупатель',
                        'phone' => $order->user->phone,
                    ],
                ],
            ],
            'due' => $claim->due_at->toIso8601String(),
            'callback_properties' => [
                'callback_url' => $this->resolveCallbackUrl(),
            ],
            'client_requirements' => [
                'taxi_class' => config('yandex-delivery.taxi_class', 'express'),
                'cargo_options' => config('yandex-delivery.cargo_options', []),
            ],
            // Режим заноса тот же, что в оценке (check-price): «до двери» только при
            // известном номере дома, иначе «от подъезда» — чтобы цена заявки совпала
            // с показанной в корзине. ALADDIN-11465.
            'skip_door_to_door' => \App\Services\YandexDelivery\YandexGoClient::shouldSkipDoorToDoor($orderAddress->house_number),
            'comment' => "Заказ №{$order->id}. Слот {$order->delivery_slot_display}",
            'zone_id' => $claim->zone_id,
        ];
    }

    protected function resolveEmergencyPhone(): string
    {
        $credentials = IntegrationConfigService::getCredentials('delivery');
        if ($credentials && !empty($credentials['emergency_phone'])) {
            return $credentials['emergency_phone'];
        }
        return config('yandex-delivery.emergency_phone', '');
    }

    protected function resolveCallbackUrl(): string
    {
        return route('webhooks.yandex-delivery');
    }
}
