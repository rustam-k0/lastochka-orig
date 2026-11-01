<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GetNearestStoreRequest;
use App\Http\Requests\Api\V1\GetStoresRequest;
use App\Http\Resources\Api\V1\StoreBonusSettingsResource;
use App\Http\Resources\Api\V1\StoreResource;
use App\Models\Address;
use App\Models\Store;
use App\Services\Store\AvailableStoreService;
use App\Services\Store\NearestStoreFinder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Stores', weight: 2)]
final class StoreController extends Controller
{
    public function __construct(
        private readonly AvailableStoreService $availableStoreService
    ) {}

    /**
     * Список магазинов
     *
     * Возвращает список активных магазинов с пагинацией.
     * При shippingMethod=pickup возвращает магазины с включённым самовывозом, этот режим приоритетнее addressId.
     * При передаче addressId авторизованным пользователем возвращает только магазины, доступные для доставки
     * на этот адрес. В запросе без токена addressId игнорируется и возвращается общий список магазинов.
     * cityId сужает выдачу до магазинов указанного города и работает во всех трёх режимах.
     *
     * @response array{hasMore: bool, data: StoreResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице, значения выше 100 усекаются до 100', type: 'int', default: 20, example: 20)]
    #[QueryParameter('addressId', description: 'ID адреса для фильтрации по доступности доставки, учитывается только в авторизованном запросе', type: 'int', example: 1)]
    #[QueryParameter('cityId', description: 'ID города для фильтрации магазинов, магазины без города в выдачу не попадают', type: 'int', required: false, example: 1)]
    #[QueryParameter('shippingMethod', description: 'Метод доставки. Значение pickup оставляет только магазины с самовывозом и имеет приоритет над addressId, остальные значения игнорируются', type: 'string', example: 'pickup')]
    #[Response(status: 403, description: 'Переданный addressId принадлежит другому пользователю, error: forbidden', type: 'array{error: string, message: string}')]
    #[Response(status: 404, description: 'Адрес не найден, error: address_not_found', type: 'array{error: string, message: string}')]
    #[Response(status: 422, description: 'Ошибка валидации query-параметров либо у адреса нет координат, error: address_missing_coordinates', type: 'array{error?: string, message: string, errors?: array<string, array<string>>}')]
    public function index(GetStoresRequest $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);
        $addressId = $request->integer('addressId');
        $shippingMethod = $request->string('shippingMethod');

        if ($shippingMethod->toString() === 'pickup') {
            return $this->indexForPickup($request, $perPage);
        }

        if (auth('sanctum')->user() && $addressId > 0) {
            return $this->indexByAddress($request, $addressId, $perPage);
        }

        $user = auth('sanctum')->user();

        $stores = Store::query()
            ->active()
            ->forUserType($user)
            ->inCity($request->integer('cityId'))
            ->with(['schedules', 'city'])
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $request->integer('page', 1));

        $resources = $stores->map(function (Store $store) {
            return (new StoreResource($store))->withDeliveryInfo([
                'deliveryPrice' => $store->delivery_price ?? 0,
                'minOrderAmount' => $store->min_order_amount ?? 0,
            ]);
        });

        return response()->json([
            'hasMore' => $stores->hasMorePages(),
            'data' => $resources,
            'total' => $stores->total(),
        ]);
    }

    private function indexForPickup(GetStoresRequest $request, int $perPage): JsonResponse
    {
        $user = auth('sanctum')->user();

        $stores = Store::query()
            ->active()
            ->forUserType($user)
            ->pickupEnabled()
            ->inCity($request->integer('cityId'))
            ->with(['schedules', 'city'])
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $request->integer('page', 1));

        $resources = $stores->map(function (Store $store) {
            return (new StoreResource($store))->withPickupInfo();
        });

        return response()->json([
            'hasMore' => $stores->hasMorePages(),
            'data' => $resources,
            'total' => $stores->total(),
        ]);
    }

    private function indexByAddress(GetStoresRequest $request, int $addressId, int $perPage): JsonResponse
    {
        $address = Address::find($addressId);

        if ($address === null) {
            return response()->json([
                'error' => 'address_not_found',
                'message' => 'Адрес не найден',
            ], 404);
        }

        // Метод достижим только из ветки с проверенным auth('sanctum')->user()
        $user = auth('sanctum')->user();

        if ($address->user_id !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Доступ запрещён',
            ], 403);
        }

        if (! $this->hasValidCoordinates($address)) {
            return response()->json([
                'error' => 'address_missing_coordinates',
                'message' => 'У адреса отсутствуют корректные координаты',
            ], 422);
        }

        $allStores = $this->availableStoreService->getAvailableStores($address, $user);

        // Фильтр по городу применяем к уже полученному от slots-api набору:
        // сервис отдаёт магазины по кодам, доп. запрос в БД тут не нужен.
        $cityId = $request->integer('cityId');
        if ($cityId > 0) {
            $allStores = $allStores->filter(
                fn (Store $store) => (int) $store->city_id === $cityId
            )->values();
        }

        $page = $request->integer('page', 1);
        $offset = ($page - 1) * $perPage;

        $total = $allStores->count();
        $stores = $allStores->slice($offset, $perPage)->values();
        $hasMore = ($offset + $perPage) < $total;

        $resources = $stores->map(function (Store $store) use ($address) {
            return (new StoreResource($store))->withDeliveryInfo(
                $this->availableStoreService->getDeliveryInfo($address, $store)
            );
        });

        return response()->json([
            'hasMore' => $hasMore,
            'data' => $resources,
            'total' => $total,
        ]);
    }

    /**
     * Ближайший магазин по координатам
     *
     * Возвращает ближайший к переданной точке активный магазин с расстоянием в километрах
     * (`distanceKm`). Учитываются только магазины с заполненными координатами.
     * Публичная ручка: токен не обязателен, но при его наличии применяются правила доступа пользователя.
     * cityId сужает поиск до одного города, shippingMethod=pickup — до магазинов с самовывозом,
     * radius ограничивает поиск расстоянием в километрах.
     *
     * @response array{data: StoreResource}
     */
    #[QueryParameter('latitude', description: 'Широта пользователя', type: 'float', required: true, example: 56.0124)]
    #[QueryParameter('longitude', description: 'Долгота пользователя', type: 'float', required: true, example: 92.8553)]
    #[QueryParameter('cityId', description: 'ID города для сужения поиска', type: 'int', required: false, example: 1)]
    #[QueryParameter('shippingMethod', description: 'Значение pickup оставляет только магазины с самовывозом', type: 'string', required: false, example: 'pickup')]
    #[QueryParameter('radius', description: 'Максимальное расстояние поиска в километрах', type: 'float', required: false, example: 30)]
    #[Response(status: 404, description: 'Подходящих магазинов с координатами нет, error: store_not_found', type: 'array{error: string, message: string}')]
    #[Response(status: 422, description: 'Ошибка валидации query-параметров', type: 'array{message: string, errors: array<string, array<string>>}')]
    public function nearest(GetNearestStoreRequest $request, NearestStoreFinder $finder): JsonResponse
    {
        $cityId = $request->integer('cityId');
        $radius = $request->has('radius') ? (float) $request->input('radius') : null;

        $nearest = $finder->findNearest(
            latitude: (float) $request->input('latitude'),
            longitude: (float) $request->input('longitude'),
            user: auth('sanctum')->user(),
            cityId: $cityId > 0 ? $cityId : null,
            pickupOnly: $request->string('shippingMethod')->toString() === 'pickup',
            radiusKm: $radius,
        );

        if ($nearest === null) {
            return response()->json([
                'error' => 'store_not_found',
                'message' => 'Подходящих магазинов не найдено',
            ], 404);
        }

        /** @var Store $store */
        $store = $nearest['store'];

        $resource = (new StoreResource($store))
            ->withDistance($nearest['distance'])
            ->withDeliveryInfo([
                'deliveryPrice' => $store->delivery_price ?? 0,
                'minOrderAmount' => $store->min_order_amount ?? 0,
            ]);

        return response()->json(['data' => $resource]);
    }

    /**
     * Детали магазина
     *
     * Возвращает детальную информацию о магазине, включая расписание работы.
     * При передаче addressId авторизованным пользователем возвращает магазин с данными доставки
     * для указанного адреса и проверяет его доступность для этого адреса.
     * В запросе без токена addressId игнорируется.
     */
    #[QueryParameter('addressId', description: 'ID адреса для получения данных доставки, учитывается только в авторизованном запросе', type: 'int', example: 1)]
    #[Response(status: 403, description: 'Переданный addressId принадлежит другому пользователю, error: forbidden')]
    #[Response(status: 404, description: 'Магазин не найден, неактивен или скрыт правилами видимости. В режиме по адресу: address_not_found, store_not_found, store_not_available', type: 'array{error?: string, message: string}')]
    #[Response(status: 422, description: 'Ошибка валидации query-параметров либо у адреса нет координат, error: address_missing_coordinates', type: 'array{error?: string, message: string, errors?: array<string, array<string>>}')]
    public function show(int $storeId, GetStoresRequest $request): StoreResource|JsonResponse
    {
        $addressId = $request->integer('addressId');

        if (auth('sanctum')->user() && $addressId > 0) {
            return $this->showByAddress($storeId, $addressId);
        }

        // Обычная логика для случая без addressId
        $user = auth('sanctum')->user();
        $store = Store::query()
            ->active()
            ->forUserType($user)
            ->find($storeId);

        if ($store === null) {
            abort(404);
        }

        $store->load(['schedules', 'city']);

        return (new StoreResource($store))->withDeliveryInfo([
            'deliveryPrice' => $store->delivery_price ?? 0,
            'minOrderAmount' => $store->min_order_amount ?? 0,
        ]);
    }

    private function showByAddress(int $storeId, int $addressId): StoreResource|JsonResponse
    {
        $address = Address::find($addressId);

        if ($address === null) {
            return response()->json([
                'error' => 'address_not_found',
                'message' => 'Адрес не найден',
            ], 404);
        }

        // Метод достижим только из ветки с проверенным auth('sanctum')->user()
        $user = auth('sanctum')->user();

        if ($address->user_id !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Доступ запрещён',
            ], 403);
        }

        if (! $this->hasValidCoordinates($address)) {
            return response()->json([
                'error' => 'address_missing_coordinates',
                'message' => 'У адреса отсутствуют корректные координаты',
            ], 422);
        }

        // Сначала найдем магазин в нашей БД с учётом B2B-доступа
        $store = Store::query()
            ->active()
            ->forUserType($user)
            ->find($storeId);

        if ($store === null) {
            return response()->json([
                'error' => 'store_not_found',
                'message' => 'Магазин не найден',
            ], 404);
        }

        // Получаем доступные магазины от внешнего API (точно так же как в indexByAddress)
        $availableStores = $this->availableStoreService->getAvailableStores($address, $user);

        // Проверяем есть ли наш магазин среди доступных (по code, а не по id)
        if (! $availableStores->contains('code', $store->code)) {
            return response()->json([
                'error' => 'store_not_available',
                'message' => 'Магазин недоступен для указанного адреса',
            ], 404);
        }

        $store->load(['schedules', 'city']);

        // Используем данные доставки от внешнего API (точно так же как в indexByAddress)
        return (new StoreResource($store))->withDeliveryInfo(
            $this->availableStoreService->getDeliveryInfo($address, $store)
        );
    }

    /**
     * Настройки бонусной системы магазина
     *
     * Возвращает признак включённости бонусов у магазина и проценты начисления/списания.
     * Публичная ручка: токен не обязателен, но при его наличии учитываются правила доступа
     * к магазину (как в остальных ручках `stores`).
     *
     * Когда бонусы у магазина выключены — `isEnabled: false`, проценты нулевые.
     */
    #[Response(status: 404, description: 'Магазин не найден, неактивен или скрыт правилами видимости')]
    public function bonusSettings(int $storeId): StoreBonusSettingsResource
    {
        $store = Store::query()
            ->active()
            ->forUserType(auth('sanctum')->user())
            ->find($storeId);

        if ($store === null) {
            abort(404);
        }

        return new StoreBonusSettingsResource($store);
    }

    private function hasValidCoordinates(Address $address): bool
    {
        if ($address->latitude === null || $address->longitude === null) {
            return false;
        }

        return (float) $address->latitude !== 0.0 || (float) $address->longitude !== 0.0;
    }

}
