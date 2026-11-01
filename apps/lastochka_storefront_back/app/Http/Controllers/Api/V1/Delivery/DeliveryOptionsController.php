<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Delivery', weight: 7)]
final class DeliveryOptionsController extends Controller
{
    /**
     * Доступные способы доставки
     *
     * Возвращает состояние трёх способов доставки для указанного магазина:
     * `own_courier`, `cdek`, `russian_post`. Порядок элементов фиксирован,
     * самовывоз из магазина в этот список не входит.
     *
     * `own_courier` всегда доступен. Доступность СДЭК и Почты России равна
     * «модуль включён И интеграция магазина активна»: `reason` содержит
     * «Модуль выключен» либо «Магазин не подключил ...», при `available: true`
     * это null. Если slots-api недоступен, обе интеграции считаются неактивными.
     */
    #[ScrambleResponse(200, type: 'array{data: array<array{method: string, label: string, available: bool, reason: string|null}>}', description: 'Состояние способов доставки для магазина')]
    #[QueryParameter('shopId', description: 'ID магазина. Валидации нет: при отсутствии параметра используется 0, и обе интеграции считаются неактивными', type: 'int', required: true, example: 1)]
    public function index(Request $request, SlotsApiClientInterface $client): JsonResponse
    {
        $shopId = (int) $request->input('shopId', 0);

        $configs = $client->getShopIntegrationConfigs($shopId, ['delivery_cdek', 'delivery_russian_post']);

        /** @var array<string, bool> $activeByType */
        $activeByType = collect($configs)
            ->keyBy('integration_type')
            ->map(fn (array $c) => (bool) ($c['is_active'] ?? false))
            ->all();

        $moduleConfig = app(ModuleConfigService::class);
        $cdekEnabled = $moduleConfig->isCdekEnabled();
        $rpEnabled = $moduleConfig->isRussianPostEnabled();

        $cdekIntegrationActive = $activeByType['delivery_cdek'] ?? false;
        $rpIntegrationActive = $activeByType['delivery_russian_post'] ?? false;

        $options = [
            [
                'method' => 'own_courier',
                'label' => 'Доставка курьером',
                'available' => true,
                'reason' => null,
            ],
            [
                'method' => 'cdek',
                'label' => 'ПВЗ СДЭК',
                'available' => $cdekEnabled && $cdekIntegrationActive,
                'reason' => ! $cdekEnabled
                    ? 'Модуль выключен'
                    : (! $cdekIntegrationActive ? 'Магазин не подключил СДЭК' : null),
            ],
            [
                'method' => 'russian_post',
                'label' => 'ОПС Почты России',
                'available' => $rpEnabled && $rpIntegrationActive,
                'reason' => ! $rpEnabled
                    ? 'Модуль выключен'
                    : (! $rpIntegrationActive ? 'Магазин не подключил Почту России' : null),
            ],
        ];

        return response()->json(['data' => $options]);
    }
}
