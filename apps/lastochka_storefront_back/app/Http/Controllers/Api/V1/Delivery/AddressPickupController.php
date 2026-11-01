<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Delivery\StorePickupAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Models\Address;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Addresses', weight: 1)]
final class AddressPickupController extends Controller
{
    /**
     * Сохранить адрес точки самовывоза
     *
     * Создаёт адрес пользователя на основе выбранного ПВЗ СДЭК или ОПС Почты России.
     * Метаданные точки выдачи сохраняются в поле `additional` и в ответе не возвращаются.
     * Адрес создаётся неактивным, активный адрес пользователя не меняется.
     */
    #[Response(status: 201, description: 'Адрес точки самовывоза создан, флаг активности не выставляется')]
    public function storePickup(StorePickupAddressRequest $request): JsonResponse
    {
        $title = $request->input('title') ?? $request->input('city', '');
        $city = $request->input('city', '');

        $address = Address::create([
            'user_id' => $request->user()->id,
            'title' => $title,
            'country' => $request->input('country'),
            'city' => $city,
            'street' => $request->input('street'),
            'house_number' => $request->input('externalId'),
            'latitude' => (float) $request->input('lat'),
            'longitude' => (float) $request->input('lng'),
            'full_address' => $title . ($city !== '' ? ', ' . $city : ''),
            'is_active' => false,
            'additional' => [
                'kind' => 'pickup',
                'provider' => $request->input('provider'),
                'external_id' => $request->input('externalId'),
                'title' => $title,
                'city' => $city,
                'region' => $request->input('region', $city),
                'street' => $request->input('street'),
                'postal_code' => $request->input('postalCode'),
                'lat' => (float) $request->input('lat'),
                'lng' => (float) $request->input('lng'),
                'phone' => $request->input('phone'),
                'raw' => $request->input('raw'),
            ],
        ]);

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(201);
    }
}
