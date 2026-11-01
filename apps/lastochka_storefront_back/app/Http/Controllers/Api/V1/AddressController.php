<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Address\CreateAddressAction;
use App\Actions\Address\SetActiveAddressAction;
use App\Actions\Address\UpdateAddressAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Address\CreateAddressRequest;
use App\Http\Requests\Api\V1\Address\UpdateAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Models\Address;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Addresses', weight: 1)]
final class AddressController extends Controller
{
    /**
     * Список адресов
     *
     * Возвращает все адреса текущего пользователя: активный первым, далее по убыванию даты создания.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = Address::query()
            ->forUser($request->user())
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->with('photos')
            ->get();

        return AddressResource::collection($addresses);
    }

    /**
     * Активный адрес
     *
     * Возвращает текущий активный адрес доставки пользователя.
     *
     * @response array{data: AddressResource|null}
     */
    public function active(Request $request): JsonResponse
    {
        $address = Address::query()
            ->forUser($request->user())
            ->active()
            ->with('photos')
            ->first();

        if (! $address) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new AddressResource($address)]);
    }

    /**
     * Создать адрес
     *
     * Создаёт новый адрес доставки для текущего пользователя и делает его активным:
     * с остальных адресов пользователя флаг активности снимается в той же транзакции.
     * Широта и долгота обязательны, пара нулевых координат отклоняется.
     */
    #[\Dedoc\Scramble\Attributes\Response(status: 201, description: 'Адрес создан и стал активным')]
    public function store(
        CreateAddressRequest $request,
        CreateAddressAction $action,
    ): JsonResponse {
        $address = $action->execute(
            $request->user(),
            $request->addressData(),
        );

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Обновить адрес
     *
     * Обновляет существующий адрес. Можно передавать только изменённые поля.
     * Широта и долгота передаются только вместе.
     */
    public function update(
        UpdateAddressRequest $request,
        Address $address,
        UpdateAddressAction $action,
    ): AddressResource {
        $this->authorize('update', $address);

        $address = $action->execute($address, $request->addressData());

        return new AddressResource($address);
    }

    /**
     * Удалить адрес
     *
     * Удаляет адрес. Если адрес был активным — флаг снимается. Тело ответа пустое (204).
     */
    public function destroy(Request $request, Address $address): Response
    {
        $this->authorize('delete', $address);

        // Если удаляемый адрес был активным — снять флаг
        if ($address->is_active) {
            $address->is_active = false;
            $address->save();
        }

        $address->delete();

        return response()->noContent();
    }

    /**
     * Установить активный адрес
     *
     * Делает указанный адрес активным для доставки.
     * Предыдущий активный адрес автоматически деактивируется.
     */
    public function setActive(
        Request $request,
        Address $address,
        SetActiveAddressAction $action,
    ): AddressResource {
        $this->authorize('setActive', $address);

        $address = $action->execute($address);

        return new AddressResource($address);
    }
}
