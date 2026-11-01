<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Delivery', weight: 7)]
final class DeliveryTariffsController extends Controller
{
    /**
     * Тарифы СДЭК
     *
     * Рассчитывает стоимость доставки в ПВЗ СДЭК для заданной точки выдачи и магазина.
     * Необходимо указать либо код ПВЗ (`code`), либо название города (`city`).
     *
     * Список тарифов проксируется из slots-api без изменения структуры; из полей
     * элемента бэкенд использует `code` и `price`. При ошибке или недоступности
     * slots-api возвращается пустой список со статусом 200.
     *
     * @throws ValidationException
     */
    #[ScrambleResponse(200, type: 'array{data: array<array<string, mixed>>}', description: 'Список тарифов СДЭК')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, array<string>>}', description: 'Ошибка валидации параметров или не указаны ни `code`, ни `city`')]
    #[QueryParameter('shopId', description: 'ID магазина', type: 'int', required: true, example: 1)]
    #[QueryParameter('code', description: 'Код ПВЗ СДЭК', type: 'string', required: false, example: 'MKHCH19')]
    #[QueryParameter('city', description: 'Название города получателя', type: 'string', required: false, example: 'Москва')]
    #[QueryParameter('weight', description: 'Вес посылки в граммах', type: 'int', required: false, example: 1000)]
    #[QueryParameter('dimensions', description: 'Габариты в формате LxWxH (см)', type: 'string', required: false, example: '30x20x10')]
    #[QueryParameter('declaredValue', description: 'Объявленная стоимость в рублях', type: 'int', required: false, example: 5000)]
    public function cdek(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'shopId' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:200'],
            'weight' => ['nullable', 'integer', 'min:1'],
            'dimensions' => ['nullable', 'string', 'max:50'],
            'declaredValue' => ['nullable', 'integer', 'min:0'],
        ], [
            'shopId.required' => 'Укажите ID магазина',
            'shopId.integer' => 'ID магазина должен быть числом',
            'shopId.min' => 'ID магазина должен быть положительным числом',
        ])->validate();

        if (empty($validated['code']) && empty($validated['city'])) {
            throw ValidationException::withMessages([
                'code' => ['Необходимо указать код ПВЗ (code) или название города (city)'],
            ]);
        }

        $params = array_filter([
            'shopId' => (int) $validated['shopId'],
            'code' => $validated['code'] ?? null,
            'city' => $validated['city'] ?? null,
            'weight' => isset($validated['weight']) ? (int) $validated['weight'] : null,
            'dimensions' => $validated['dimensions'] ?? null,
            'declaredValue' => isset($validated['declaredValue']) ? (int) $validated['declaredValue'] : null,
        ], fn ($v) => $v !== null);

        $result = $client->getCdekTariffs($params);

        return response()->json(['data' => $result]);
    }

    /**
     * Тарифы Почты России
     *
     * Рассчитывает стоимость доставки через Почту России для заданного индекса ОПС и магазина.
     *
     * Список тарифов проксируется из slots-api без изменения структуры; из полей
     * элемента бэкенд использует `code` и `price`. При ошибке или недоступности
     * slots-api возвращается пустой список со статусом 200.
     *
     * @throws ValidationException
     */
    #[ScrambleResponse(200, type: 'array{data: array<array<string, mixed>>}', description: 'Список тарифов Почты России')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, array<string>>}', description: 'Ошибка валидации параметров')]
    #[QueryParameter('shopId', description: 'ID магазина', type: 'int', required: true, example: 1)]
    #[QueryParameter('index', description: 'Почтовый индекс ОПС (6 цифр)', type: 'string', required: true, example: '367000')]
    #[QueryParameter('weight', description: 'Вес посылки в граммах', type: 'int', required: false, example: 1000)]
    public function russianPost(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'shopId' => ['required', 'integer', 'min:1'],
            'index' => ['required', 'string', 'regex:/^\d{6}$/'],
            'weight' => ['nullable', 'integer', 'min:1'],
        ], [
            'shopId.required' => 'Укажите ID магазина',
            'shopId.integer' => 'ID магазина должен быть числом',
            'shopId.min' => 'ID магазина должен быть положительным числом',
            'index.required' => 'Укажите почтовый индекс',
            'index.regex' => 'Почтовый индекс должен состоять из 6 цифр',
        ])->validate();

        $params = array_filter([
            'shopId' => (int) $validated['shopId'],
            'index' => $validated['index'],
            'weight' => isset($validated['weight']) ? (int) $validated['weight'] : null,
        ], fn ($v) => $v !== null);

        $result = $client->getRpTariffs($params);

        return response()->json(['data' => $result]);
    }
}
