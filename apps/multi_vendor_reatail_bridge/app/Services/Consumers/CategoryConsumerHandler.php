<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\CategoryConverter;
use App\Services\Hash\EntityHashService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий категорий (category.*).
 * 
 * Категория создаётся только для указанного shop_code.
 * Дублирование на другие sites происходит "лениво" - при обработке товара,
 * если ему нужна категория в другом site.
 */
class CategoryConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'category';

    protected CategoryConverter $converter;
    protected RetailCRMService $retailCrmService;
    protected EntityHashService $hashService;

    public function __construct(
        CategoryConverter $converter,
        RetailCRMService $retailCrmService,
        EntityHashService $hashService
    ) {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
        $this->hashService = $hashService;
    }

    /**
     * Валидация структуры категории.
     */
    protected function validateStructure(array $data): bool
    {
        return parent::validateStructure($data)
            && isset($data['title']);
    }

    /**
     * Создаёт site в RetailCRM если не существует.
     */
    public function ensureSiteExists(string $siteCode): bool
    {
        $response = $this->retailCrmService->getSites();
        
        if ($response && !empty($response->sites)) {
            foreach ($response->sites as $code => $siteData) {
                $existingCode = is_array($siteData) ? ($siteData['code'] ?? $code) : ($siteData->code ?? $code);
                if ($existingCode === $siteCode) {
                    return true;
                }
            }
        }

        Log::info("Создаём site в RetailCRM: {$siteCode}");
        
        $siteData = [
            'code' => $siteCode,
            'name' => ucfirst($siteCode),
            'active' => true,
        ];

        return $this->retailCrmService->editSite($siteData);
    }

    /**
     * Деактивирует категорию в RetailCRM при получении delete-события.
     */
    protected function deactivateInRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?bool {
        $siteCode = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

        try {
            $response = $this->retailCrmService->updateProductGroup(
                $metadata->entity_id,
                ['active' => false, 'site' => $siteCode],
                'id'
            );

            if ($response && isset($response->id)) {
                $this->hashService->saveHashAfterSync('category', (string)$response->id, $siteCode);

                Log::info("Категория деактивирована в RetailCRM", [
                    'entity_id' => $metadata->entity_id,
                    'external_id' => $data['id'] ?? 'unknown',
                    'site' => $siteCode,
                ]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not found')) {
                Log::info("Категория уже не существует в RetailCRM (404)", [
                    'entity_id' => $metadata->entity_id,
                    'external_id' => $data['id'] ?? 'unknown',
                ]);
                return true;
            }

            Log::error("Ошибка деактивации категории в RetailCRM: {$e->getMessage()}", [
                'entity_id' => $metadata->entity_id,
                'external_id' => $data['id'] ?? 'unknown',
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Синхронизирует категорию с RetailCRM.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        $siteCode = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');
        
        // Проверка на изменения - предотвращает цикл событий
        if ($metadata->entity_id) {
            $converted = $this->converter->toRetailCRM($data);
            if (!$this->hashService->hasChanged('categories', $data['id'], $converted, $siteCode)) {
                Log::info("Пропуск синхронизации категории - данные не изменились", [
                    'category_id' => $data['id'],
                    'entity_id' => $metadata->entity_id,
                ]);
                return (string)$metadata->entity_id;
            }
        }

        // Убеждаемся что site существует
        $this->ensureSiteExists($siteCode);
        try {
            // Конвертируем данные в формат RetailCRM
            $categoryData = $this->converter->toRetailCRM($data);
            $categoryData['site'] = $siteCode ?? $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

            // Если есть родительская категория, ищем её RetailCRM ID для этого site
            if (!empty($data['parent_id']) && !empty($data['parent_source'])) {
                $parentRetailId = $this->findRetailCrmId(
                    'category',
                    $data['parent_source'],
                    $data['parent_id'],
                    $siteCode
                );

                if ($parentRetailId) {
                    $categoryData['parentId'] = (int)$parentRetailId;
                } else {
                    Log::warning("Родительская категория не найдена для site {$siteCode}", [
                        'category_id' => $data['id'],
                        'parent_id' => $data['parent_id'],
                    ]);
                    // Создаём без родителя
                }
            }

            // Если категория уже существует в RetailCRM (есть entity_id), обновляем
            if ($metadata->entity_id) {
                $updateSuccessful = false;
                $needsFallbackCreate = false;
                
                try {
                    $response = $this->retailCrmService->updateProductGroup(
                        $metadata->entity_id,
                        $categoryData,
                        'id'
                    );

                    if ($response && isset($response->id)) {
                        $this->hashService->saveHashAfterSync('category', (string)$response->id, $siteCode);
                        return (string)$response->id;
                    }
                } catch (\Exception $e) {
                    // 404 = категория не существует в RetailCRM, нужен CREATE
                    if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not found')) {
                        Log::warning("Категория не найдена в RetailCRM (404), пробуем CREATE", [
                            'entity_id' => $metadata->entity_id,
                            'external_id' => $data['id'],
                            'site' => $siteCode,
                        ]);
                        $needsFallbackCreate = true;
                    } else {
                        // Возможно ошибка связана с parent - пробуем без родителя
                        if (isset($categoryData['parentId'])) {
                            Log::warning("Retry update без parentId (родитель не найден для site)", [
                                'category_id' => $metadata->entity_id,
                                'parent_id' => $categoryData['parentId'],
                                'site' => $siteCode,
                            ]);
                            $categoryDataNoParent = $categoryData;
                            unset($categoryDataNoParent['parentId']);
                            
                            try {
                                $response = $this->retailCrmService->updateProductGroup(
                                    $metadata->entity_id,
                                    $categoryDataNoParent,
                                    'id'
                                );

                                if ($response && isset($response->id)) {
                                    $this->hashService->saveHashAfterSync('category', (string)$response->id, $siteCode);
                                    return (string)$response->id;
                                }
                            } catch (\Exception $e2) {
                                if (str_contains($e2->getMessage(), '404') || str_contains($e2->getMessage(), 'Not found')) {
                                    $needsFallbackCreate = true;
                                } else {
                                    throw $e2;
                                }
                            }
                        }
                        
                        if (!$needsFallbackCreate) {
                            throw $e;
                        }
                    }
                }

                // Fallback: создаём категорию заново если UPDATE вернул 404
                if ($needsFallbackCreate) {
                    Log::info("Fallback CREATE для категории (entity_id был невалидным)", [
                        'old_entity_id' => $metadata->entity_id,
                        'external_id' => $data['id'],
                        'site' => $siteCode,
                    ]);
                    // Очищаем entity_id чтобы создать заново
                    $metadata->entity_id = null;
                }
            }

            // Создаём новую категорию
            $response = $this->retailCrmService->createProductGroup($categoryData);

            if ($response && isset($response->id)) {
                $this->hashService->saveHashAfterSync('category', (string)$response->id, $siteCode);
                return (string)$response->id;
            }

            // Если ошибка связана с parent - пробуем без родителя
            if (isset($categoryData['parentId'])) {
                Log::warning("Retry create без parentId (родитель не найден для site)", [
                    'external_id' => $data['id'],
                    'parent_id' => $categoryData['parentId'],
                    'site' => $siteCode,
                ]);
                unset($categoryData['parentId']);
                
                $response = $this->retailCrmService->createProductGroup($categoryData);

                if ($response && isset($response->id)) {
                    $this->hashService->saveHashAfterSync('category', (string)$response->id, $siteCode);
                    return (string)$response->id;
                }
            }

            Log::error("Не удалось создать категорию в RetailCRM", [
                'external_id' => $data['id'],
                'site' => $siteCode,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации категории с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'site' => $siteCode,
            ]);

            return null;
        }
    }
}
