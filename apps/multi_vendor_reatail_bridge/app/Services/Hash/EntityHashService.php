<?php

namespace App\Services\Hash;

use App\Models\System\EntityHash;
use App\Services\Converters\ProductConverter;
use App\Services\Converters\CategoryConverter;
use App\Services\Converters\ShopConverter;
use App\Services\Converters\UserConverter;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления хешами сущностей.
 * 
 * Хеш строится на основе данных, полученных из RetailCRM (GET),
 * чтобы избежать ложных срабатываний при пуллинге из-за трансформации данных.
 */
class EntityHashService
{
    protected RetailCRMService $retailCrmService;
    protected ProductConverter $productConverter;
    protected CategoryConverter $categoryConverter;
    protected ShopConverter $shopConverter;
    protected UserConverter $userConverter;

    public function __construct(
        RetailCRMService $retailCrmService,
        ProductConverter $productConverter,
        CategoryConverter $categoryConverter,
        ShopConverter $shopConverter,
        UserConverter $userConverter
    ) {
        $this->retailCrmService = $retailCrmService;
        $this->productConverter = $productConverter;
        $this->categoryConverter = $categoryConverter;
        $this->shopConverter = $shopConverter;
        $this->userConverter = $userConverter;
    }

    /**
     * Вычисляет хеш для данных сущности.
     * Исключает updated_at, так как он меняется при каждой конвертации.
     */
    public function computeHash(array $data): string
    {
        $dataForHash = $data;
        unset($dataForHash['updated_at']);
        
        return md5(json_encode($dataForHash));
    }

    /**
     * Проверяет, изменились ли данные сущности по сравнению с сохранённым хешем.
     *
     * @param string $entityType Тип сущности (products, categories, shops, etc.)
     * @param string $entityId ID сущности
     * @param array $data Конвертированные данные сущности
     * @param string|null $shopCode Код магазина (для мультишопных сущностей)
     * @return bool True если данные изменились или сущность новая
     */
    public function hasChanged(string $entityType, string $entityId, array $data, ?string $shopCode = null): bool
    {
        $hash = $this->computeHash($data);
        $resolvedShopCode = $shopCode ?? 'default';

        $existing = EntityHash::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('shop_code', $resolvedShopCode)
            ->first();

        if (!$existing) {
            EntityHash::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'shop_code' => $resolvedShopCode,
                'hash' => $hash,
            ]);

            Log::debug("EntityHashService: Новый хеш для {$entityType}:{$entityId}:{$resolvedShopCode}");
            return true;
        }

        if ($existing->hash !== $hash) {
            $oldHash = $existing->hash;
            $existing->update(['hash' => $hash]);

            Log::debug("EntityHashService: Хеш изменился для {$entityType}:{$entityId}:{$resolvedShopCode}", [
                'old_hash' => $oldHash,
                'new_hash' => $hash,
            ]);
            return true;
        }

        Log::debug("EntityHashService: Хеш не изменился для {$entityType}:{$entityId}:{$resolvedShopCode}");
        return false;
    }

    /**
     * Сохраняет хеш сущности после успешной синхронизации с RetailCRM.
     * Делает GET-запрос чтобы получить данные в том виде, как их сохранила RetailCRM.
     * 
     * @param string $entityType Тип сущности (product, category, shop, user)
     * @param string $retailCrmId ID сущности в RetailCRM
     * @param string|null $site Код магазина (shop_code для мультишопных сущностей)
     * @return bool True если хеш успешно сохранён
     */
    public function saveHashAfterSync(string $entityType, string $retailCrmId, ?string $site = null): bool
    {
        try {
            $data = $this->fetchFromRetailCRM($entityType, $retailCrmId, $site);

            if (!$data) {
                Log::warning("EntityHashService: Не удалось получить данные из RetailCRM для хеширования", [
                    'entity_type' => $entityType,
                    'retailcrm_id' => $retailCrmId,
                ]);
                return false;
            }

            $converted = $this->convertFromRetailCRM($entityType, $data);

            if (!$converted) {
                Log::warning("EntityHashService: Не удалось конвертировать данные для хеширования", [
                    'entity_type' => $entityType,
                    'retailcrm_id' => $retailCrmId,
                ]);
                return false;
            }

            $hash = $this->computeHash($converted);
            // Для продуктов polling использует externalId (id из Bagisto)
            // Но converted['id'] без metadata = retailCrmId, поэтому берём externalId из исходных данных
            $entityId = $data['externalId'] ?? $converted['id'] ?? $retailCrmId;

            // Используем entity_type во множественном числе для консистентности с PollEntityCommand
            $entityTypePlural = $this->pluralize($entityType);

            $resolvedShopCode = $site ?? 'default';

            EntityHash::updateOrCreate(
                [
                    'entity_type' => $entityTypePlural,
                    'entity_id' => $entityId,
                    'shop_code' => $resolvedShopCode,
                ],
                [
                    'hash' => $hash,
                ]
            );

            Log::info("EntityHashService: Хеш сохранён после синхронизации", [
                'entity_type' => $entityTypePlural,
                'entity_id' => $entityId,
                'shop_code' => $resolvedShopCode,
                'retailcrm_id' => $retailCrmId,
                'hash' => $hash,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("EntityHashService: Ошибка при сохранении хеша: " . $e->getMessage(), [
                'entity_type' => $entityType,
                'retailcrm_id' => $retailCrmId,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Получает данные сущности из RetailCRM.
     */
    protected function fetchFromRetailCRM(string $entityType, string $retailCrmId, ?string $site = null): ?array
    {
        switch ($entityType) {
            case 'product':
                // При запросе по ID пагинация не нужна (вызывает ошибку 400)
                $response = $this->retailCrmService->getProducts(['ids' => [(int)$retailCrmId]]);
                if ($response && !empty($response->products)) {
                    $productData = json_decode(json_encode($response->products[0]), true);
                    // Добавляем site для правильной конвертации
                    if ($site) {
                        $productData['site'] = $site;
                    }
                    return $productData;
                }
                break;

            case 'category':
                // При запросе по ID пагинация не нужна
                $response = $this->retailCrmService->getProductGroups(['ids' => [(int)$retailCrmId]]);
                if ($response) {
                    $items = $response->productGroup ?? $response->productGroups ?? [];
                    if (!empty($items)) {
                        $item = is_array($items) ? (array_values((array)$items)[0] ?? null) : $items;
                        if ($item) {
                            return json_decode(json_encode($item), true);
                        }
                    }
                }
                break;

            case 'shop':
                $response = $this->retailCrmService->getSites();
                if ($response && !empty($response->sites)) {
                    foreach ($response->sites as $siteData) {
                        $siteArr = json_decode(json_encode($siteData), true);
                        if (($siteArr['code'] ?? '') === $retailCrmId || ($siteArr['id'] ?? '') == $retailCrmId) {
                            return $siteArr;
                        }
                    }
                }
                break;

            case 'user':
                // Для пользователей (collectors/couriers) сложнее, пока пропускаем
                Log::debug("EntityHashService: Получение user из RetailCRM пока не реализовано");
                return null;
        }

        return null;
    }

    /**
     * Конвертирует данные из формата RetailCRM.
     */
    protected function convertFromRetailCRM(string $entityType, array $data): ?array
    {
        switch ($entityType) {
            case 'product':
                return $this->productConverter->fromRetailCRM($data);

            case 'category':
                return $this->categoryConverter->fromRetailCRM($data);

            case 'shop':
                return $this->shopConverter->fromRetailCRM($data);

            case 'user':
                return $this->userConverter->fromRetailCRM($data);
        }

        return null;
    }

    /**
     * Преобразует тип сущности в множественное число.
     */
    protected function pluralize(string $entityType): string
    {
        $map = [
            'product' => 'products',
            'category' => 'categories',
            'shop' => 'shops',
            'user' => 'users',
            'collector' => 'collectors',
            'courier' => 'couriers',
        ];

        return $map[$entityType] ?? $entityType . 's';
    }
}
