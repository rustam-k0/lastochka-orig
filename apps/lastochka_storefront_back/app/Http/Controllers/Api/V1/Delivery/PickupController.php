<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Pickup', weight: 8)]
final class PickupController extends Controller
{
    /**
     * Список ПВЗ СДЭК
     *
     * Возвращает список пунктов выдачи заказов СДЭК с фильтрацией по bbox, поиску и типу.
     * Данные проксируются из slots-api без изменения структуры. При недоступности
     * slots-api возвращается пустой список со статусом 200.
     */
    #[ScrambleResponse(200, type: 'array{data: array<array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>}', description: 'Список ПВЗ СДЭК')]
    #[QueryParameter('bbox', description: 'Bounding box: lat1,lng1,lat2,lng2', type: 'string', required: false, example: '55.70,37.60,55.80,37.70')]
    #[QueryParameter('q', description: 'Строка поиска', type: 'string', required: false)]
    #[QueryParameter('limit', description: 'Максимальное количество результатов', type: 'int', required: false, example: 50)]
    #[QueryParameter('type', description: 'Тип ПВЗ: PVZ|POSTAMAT. Если не передан, используется PVZ', type: 'string', required: false)]
    public function cdek(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $params = array_filter([
            'bbox' => $request->input('bbox'),
            'q' => $request->input('q'),
            'limit' => $request->input('limit') !== null ? (int) $request->input('limit') : null,
            'type' => $request->input('type'),
        ], fn ($v) => $v !== null);

        $result = $client->getCdekPvz($params);

        return response()->json(['data' => $result]);
    }

    /**
     * Один ПВЗ СДЭК по коду
     *
     * Возвращает подробную информацию о конкретном пункте выдачи СДЭК.
     * Ответ 404 отдаётся и когда точка не найдена, и когда slots-api недоступен
     * или вернул ошибку.
     */
    #[ScrambleResponse(200, type: 'array{data: array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}}', description: 'Данные ПВЗ СДЭК')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'ПВЗ СДЭК не найден')]
    #[PathParameter('code', description: 'Код ПВЗ СДЭК', type: 'string', example: 'MKHCH19')]
    public function cdekShow(string $code, SlotsApiClientInterface $client): JsonResponse
    {
        $pickup = $client->getCdekPickupByCode($code);

        if ($pickup === null) {
            return response()->json(['message' => 'ПВЗ СДЭК не найден'], 404);
        }

        return response()->json(['data' => $pickup]);
    }

    /**
     * Список отделений Почты России
     *
     * Возвращает список ОПС Почты России с фильтрацией по bbox, поиску и типу.
     * Данные проксируются из slots-api без изменения структуры. При недоступности
     * slots-api возвращается пустой список со статусом 200.
     */
    #[ScrambleResponse(200, type: 'array{data: array<array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>}', description: 'Список отделений Почты России')]
    #[QueryParameter('bbox', description: 'Bounding box: lat1,lng1,lat2,lng2', type: 'string', required: false, example: '55.70,37.60,55.80,37.70')]
    #[QueryParameter('q', description: 'Строка поиска', type: 'string', required: false)]
    #[QueryParameter('limit', description: 'Максимальное количество результатов', type: 'int', required: false, example: 50)]
    #[QueryParameter('opsType', description: 'Тип отделения. Если не передан, используется ГОПС', type: 'string', required: false)]
    public function russianPost(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $params = array_filter([
            'bbox' => $request->input('bbox'),
            'q' => $request->input('q'),
            'limit' => $request->input('limit') !== null ? (int) $request->input('limit') : null,
            'opsType' => $request->input('opsType'),
        ], fn ($v) => $v !== null);

        $result = $client->getRpOffices($params);

        return response()->json(['data' => $result]);
    }

    /**
     * Одно отделение Почты России по индексу
     *
     * Возвращает подробную информацию о конкретном ОПС по почтовому индексу.
     * Значение `index` ограничено маршрутом до 6 цифр. Ответ 404 отдаётся и когда
     * отделение не найдено, и когда slots-api недоступен или вернул ошибку.
     */
    #[ScrambleResponse(200, type: 'array{data: array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}}', description: 'Данные отделения Почты России')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Отделение Почты России не найдено')]
    #[PathParameter('index', description: 'Почтовый индекс ОПС (6 цифр)', type: 'string', example: '367000')]
    public function russianPostShow(string $index, SlotsApiClientInterface $client): JsonResponse
    {
        $office = $client->getRpOfficeByIndex($index);

        if ($office === null) {
            return response()->json(['message' => 'Отделение Почты России не найдено'], 404);
        }

        return response()->json(['data' => $office]);
    }

    /**
     * Города для самовывоза
     *
     * Возвращает список городов, в которых доступен самовывоз через СДЭК или Почту России.
     * При недоступности slots-api возвращается пустой список со статусом 200.
     */
    #[ScrambleResponse(200, type: 'array{data: array<array{name: string, slug: string, region: string, zip: string, lat: float, lng: float}>}', description: 'Список городов для самовывоза')]
    #[QueryParameter('q', description: 'Строка поиска по названию города', type: 'string', required: false)]
    #[QueryParameter('limit', description: 'Максимальное количество результатов', type: 'int', required: false, example: 20)]
    public function cities(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $q = $request->input('q');
        $limit = $request->input('limit') !== null ? (int) $request->input('limit') : null;

        $result = $client->getPickupCities($q, $limit);

        return response()->json(['data' => $result]);
    }
}
