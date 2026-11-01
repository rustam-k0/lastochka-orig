<?php

namespace App\Console\Commands\RetailCRM;

use App\Models\System\EventSyncMetadata;
use App\Models\SyncState;
use App\Services\Converters\CategoryConverter;
use App\Services\Converters\ProductConverter;
use App\Services\Converters\ShopConverter;
use App\Services\Converters\UserConverter;
use App\Services\Hash\EntityHashService;
use App\Services\Outbox\OutboxService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PollEntityCommand extends Command
{
    protected $signature = 'retail:poll {entity : Entity to poll (products, categories, shops, all)} {--full : Force full sync}';

    protected $description = 'Poll data from RetailCRM and publish to RabbitMQ via Outbox';

    protected $retailService;
    protected $outbox;
    protected $productConverter;
    protected $categoryConverter;
    protected $shopConverter;
    protected $userConverter;
    protected $hashService;

    public function __construct(
        RetailCRMService $retailService,
        OutboxService $outbox,
        ProductConverter $productConverter,
        CategoryConverter $categoryConverter,
        ShopConverter $shopConverter,
        UserConverter $userConverter,
        EntityHashService $hashService
    ) {
        parent::__construct();
        $this->retailService = $retailService;
        $this->outbox = $outbox;
        $this->productConverter = $productConverter;
        $this->categoryConverter = $categoryConverter;
        $this->shopConverter = $shopConverter;
        $this->userConverter = $userConverter;
        $this->hashService = $hashService;
    }

    public function handle()
    {
        // Переключаем дефолтный канал логирования на 'polling'
        // чтобы все сервисы (RetailCRMService, OutboxService и т.д.) писали туда
        $originalChannel = Config::get('logging.default');
        Config::set('logging.default', 'polling');

        $entity = $this->argument('entity');
        $full = $this->option('full');

        $this->info("Starting polling for {$entity}...");

        try {
            switch ($entity) {
                case 'all':
                    $this->info("Polling all entities...");
                    $this->pollCategories($full);
                    $this->pollProducts($full);
                    $this->pollShops();
                    $this->pollCollectors();
                    $this->pollCouriers();
                    break;
                case 'products':
                    $this->pollProducts($full);
                    break;
                case 'categories':
                    $this->pollCategories($full);
                    break;
                case 'shops':
                    $this->pollShops();
                    break;
                case 'collectors':
                    $this->pollCollectors();
                    break;
                case 'couriers':
                    $this->pollCouriers();
                    break;
                default:
                    $this->error("Unknown entity: {$entity}");
                    Config::set('logging.default', $originalChannel);
                    return 1;
            }

            $result = 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::channel('polling')->error("Polling error for {$entity}", ['exception' => $e]);
            $result = 1;
        } finally {
            // Восстанавливаем оригинальный канал логирования
            Config::set('logging.default', $originalChannel);
        }

        $this->info("Polling for {$entity} completed.");
        return $result;
    }

    protected function pollProducts($full)
    {
        $lastSync = $this->getLastSync('products');
        $baseFilter = [];

        if (!$full && $lastSync) {
            // RetailCRM API ожидает дату в часовом поясе аккаунта (обычно Europe/Moscow)
            // SDK ожидает объект DateTime, а не строку
            $date = $lastSync->copy()->timezone('Europe/Moscow');

            $baseFilter['sinceUpdatedAt'] = $date;

            $this->info("Syncing products updated since " . $date->format('Y-m-d H:i:s'));
            Log::channel('polling')->info("PollProducts: Syncing since " . $date->format('Y-m-d H:i:s'));
        } else {
            $this->info("Full sync of products");
            Log::channel('polling')->info("PollProducts: Starting full sync");
        }

        // Получаем список магазинов для фильтрации товаров по каждому магазину
        $sites = $this->getActiveSites();
        if (empty($sites)) {
            $this->error("No active sites found in RetailCRM");
            Log::channel('polling')->error("PollProducts: No active sites found");
            return;
        }

        $this->info("Found " . count($sites) . " active sites: " . implode(', ', $sites));
        Log::channel('polling')->info("PollProducts: Processing products for sites", ['sites' => $sites]);

        $maxUpdatedAt = null;
        $limit = 100;

        // Обрабатываем товары для каждого магазина отдельно
        foreach ($sites as $siteCode) {
            $this->info("Processing products for site: {$siteCode}");
            Log::channel('polling')->info("PollProducts: Starting sync for site {$siteCode}");

            $filter = array_merge($baseFilter, ['sites' => [$siteCode]]);
            Log::channel('polling')->info("PollProducts: Filter params for site {$siteCode}:", $filter);

            $page = 1;

            do {
                Log::channel('polling')->info("PollProducts: Requesting page {$page} for site {$siteCode}");
                $response = $this->retailService->getProducts($filter, $page, $limit);

                if (!$response) {
                    $this->error("Failed to get response from RetailCRM for site {$siteCode}");
                    Log::channel('polling')->error("PollProducts: Failed to get response from RetailCRM for site {$siteCode}");
                    break;
                }

                $products = $response->products ?? [];
                if (empty($products)) {
                    Log::channel('polling')->info("PollProducts: No more products found on page {$page} for site {$siteCode}");
                    break;
                }

                $this->info("Site {$siteCode}: Processing page {$page}, count: " . count($products));
                Log::channel('polling')->info("PollProducts: Site {$siteCode}, page {$page}, found " . count($products) . " products");

                // Pre-fetch metadata
                $ids = array_map(fn($p) => (string)($p['id'] ?? ''), json_decode(json_encode($products), true));
                $metadataMap = EventSyncMetadata::where('entity_type', 'product')
                    ->whereIn('entity_id', $ids)
                    ->get()
                    ->keyBy('entity_id');

                foreach ($products as $productData) {
                    $productDataArr = json_decode(json_encode($productData), true);

                    // Добавляем site в данные товара для правильной резолюции shop_code
                    $productDataArr['site'] = $siteCode;

                    $retailCrmId = (string)($productDataArr['id'] ?? 'unknown');
                    Log::channel('polling')->debug("PollProducts: Processing product ID: {$retailCrmId} for site {$siteCode}", ['data' => $productDataArr]);

                    $metadata = $metadataMap[$retailCrmId] ?? null;
                    $converted = $this->productConverter->fromRetailCRM($productDataArr, $metadata);

                    // Проверка на изменения через хеш (с учётом shop_code для мультишопных сущностей)
                    if ($this->hashService->hasChanged('products', $converted['id'], $converted, $converted['shop_code'] ?? null)) {
                        // Публикация product.updated отключена по умолчанию: retailbridge
                        // шлёт частичный payload (только image_url без media[]/image_urls[])
                        // и storefront `processImage` стирает остальные фото товара.
                        // Чтобы вернуть публикацию — задать в .env: RB_POLL_PRODUCTS_PUBLISH=true.
                        if (env('RB_POLL_PRODUCTS_PUBLISH', false)) {
                            $this->outbox->dispatch('product', 'product.updated', $converted, $converted['id']);
                            Log::channel('polling')->info("PollProducts: Dispatched product ID: {$converted['id']} (site: {$siteCode}) to outbox (CHANGED)");
                        } else {
                            Log::channel('polling')->info("PollProducts: Publish disabled (default), skipping dispatch for product ID: {$converted['id']} (site: {$siteCode})");
                        }
                    } else {
                        Log::channel('polling')->info("PollProducts: Skipping product ID: {$converted['id']} (site: {$siteCode}) (NO CHANGES)");
                    }

                    if (isset($productDataArr['updatedAt'])) {
                        $updatedAtVal = $productDataArr['updatedAt'];
                        if (is_array($updatedAtVal) && isset($updatedAtVal['date'])) {
                            $updatedAtVal = $updatedAtVal['date'];
                        }
                        $updatedAt = \Carbon\Carbon::parse($updatedAtVal);
                        if (!$maxUpdatedAt || $updatedAt->gt($maxUpdatedAt)) {
                            $maxUpdatedAt = $updatedAt;
                        }
                    }
                }

                if (isset($response->pagination) && $response->pagination->currentPage < $response->pagination->totalPageCount) {
                    $page++;
                    usleep(100000); // 0.1s delay to be safe with limits
                } else {
                    break;
                }
            } while (true);

            Log::channel('polling')->info("PollProducts: Finished sync for site {$siteCode}");
        }

        if ($maxUpdatedAt) {
            $this->updateLastSync('products', $maxUpdatedAt);
        } else {
            // Если полной синхронизации и ничего не пришло, всё равно обновляем
            if (empty($baseFilter)) {
                $this->updateLastSync('products', now());
            }
        }
    }

    /**
     * Получает список активных магазинов (sites) из RetailCRM.
     *
     * @return array Массив кодов активных магазинов
     */
    protected function getActiveSites(): array
    {
        $response = $this->retailService->getSites();

        if (!$response || empty($response->sites)) {
            return [];
        }

        $activeSites = [];
        foreach ($response->sites as $siteCode => $siteData) {
            $site = (array)$siteData;
            // Включаем только активные магазины
            if ($site['active'] ?? true) {
                $activeSites[] = $site['code'];
            }
        }

        return $activeSites;
    }

    protected function pollCategories($full)
    {
        $lastSync = $this->getLastSync('categories');
        $filter = [];

        if (!$full && $lastSync) {
             $date = $lastSync->copy()->timezone('Europe/Moscow');
             $this->info("Syncing categories. Checking for changes locally. Last sync: " . $date->format('Y-m-d H:i:s'));
             Log::channel('polling')->info("PollCategories: Syncing. Checking changes. Last sync: " . $date->format('Y-m-d H:i:s'));
        } else {
            $this->info("Full sync of categories");
            Log::channel('polling')->info("PollCategories: Starting full sync");
        }

        Log::channel('polling')->info("PollCategories: Filter params:", $filter);

        $page = 1;
        $limit = 100;

        do {
            Log::channel('polling')->info("PollCategories: Requesting page {$page}");
            $response = $this->retailService->getProductGroups($filter, $page, $limit);

            if (!$response) {
                 Log::channel('polling')->error("PollCategories: Failed to get response from RetailCRM");
                 break;
            }

            $items = $response->productGroup ?? $response->productGroups ?? [];

            if (empty($items)) {
                Log::channel('polling')->info("PollCategories: No more categories found on page {$page}");
                break;
            }

            $this->info("Processing page {$page}, count: " . count($items));
            Log::channel('polling')->info("PollCategories: Processing page {$page}, found " . count($items) . " categories");

            // Pre-fetch metadata
            $ids = array_map(fn($i) => (string)($i['id'] ?? ''), json_decode(json_encode($items), true));
            $metadataMap = EventSyncMetadata::where('entity_type', 'category')
                ->whereIn('entity_id', $ids)
                ->get()
                ->keyBy('entity_id');

            foreach ($items as $item) {
                $itemArr = json_decode(json_encode($item), true);
                $retailCrmId = (string)($itemArr['id'] ?? 'unknown');

                $metadata = $metadataMap[$retailCrmId] ?? null;
                $converted = $this->categoryConverter->fromRetailCRM($itemArr, $metadata);

                // Категории только принимаются из RabbitMQ, не публикуются обратно (receive-only mode).
                // Per-category DEBUG-логи убраны: при минутном polling ~1170 категорий они
                // заполняли диск (десятки ГБ в polling-логе). Итог логируется по странице выше.
            }

            if (isset($response->pagination) && $response->pagination->currentPage < $response->pagination->totalPageCount) {
                $page++;
                usleep(100000);
            } else {
                break;
            }

        } while (true);

        $this->updateLastSync('categories', now());
        Log::channel('polling')->info("PollCategories: Sync completed");
    }

    protected function pollShops()
    {
        Log::channel('polling')->info("PollShops: Starting sync");
        $response = $this->retailService->getSites();

        if (!$response || empty($response->sites)) {
            Log::channel('polling')->warning("PollShops: No sites found or empty response");
            return;
        }

        $allSites = $response->sites;

        // Фильтруем sites помеченные как удалённые (суффикс "(УДАЛЁН)" в name)
        $sites = [];
        foreach ($allSites as $site) {
            $siteArr = (array)$site;
            $name = $siteArr['name'] ?? '';
            if (!str_contains($name, '(УДАЛЁН)')) {
                $sites[] = $site;
            }
        }

        $skipped = count($allSites) - count($sites);
        $this->info("Processing shops, count: " . count($sites) . " (total: " . count($allSites) . ", deleted skipped: {$skipped})");
        Log::channel('polling')->info("PollShops: Found " . count($allSites) . " shops, " . count($sites) . " active, {$skipped} deleted skipped");

        // Pre-fetch metadata
        $ids = array_map(fn($s) => (string)($s['code'] ?? $s['id'] ?? ''), json_decode(json_encode($sites), true));
        $metadataMap = EventSyncMetadata::where('entity_type', 'shop')
            ->whereIn('entity_id', $ids)
            ->get()
            ->keyBy('entity_id');

        foreach ($sites as $site) {
            $siteArr = json_decode(json_encode($site), true);
            $retailCrmId = (string)($siteArr['code'] ?? $siteArr['id'] ?? 'unknown');

            $metadata = $metadataMap[$retailCrmId] ?? null;
            $converted = $this->shopConverter->fromRetailCRM($siteArr, $metadata);

            // Проверка на изменения через хеш (с учётом shop_code для мультишопных сущностей)
            if ($this->hashService->hasChanged('shops', $converted['id'], $converted, $converted['shop_code'] ?? null)) {
                $this->outbox->dispatch('shop', 'shop.updated', $converted, $converted['id']);
                Log::channel('polling')->info("PollShops: Dispatched shop ID: {$converted['id']} to outbox (CHANGED)");
            } else {
                Log::channel('polling')->info("PollShops: Skipping shop ID: {$converted['id']} (NO CHANGES)");
            }
        }

        $this->updateLastSync('shops', now());
        Log::channel('polling')->info("PollShops: Sync completed");
    }

    protected function pollCollectors()
    {
        $dictionaryCode = config('retailcrm.dictionaries.collectors', 'collectors');
        Log::channel('polling')->info("PollCollectors: Starting sync for dictionary '{$dictionaryCode}'");

        try {
            $response = $this->retailService->getCustomDictionary($dictionaryCode);

            if (!$response || !$response->customDictionary) {
                $this->error("PollCollectors: Dictionary '{$dictionaryCode}' not found");
                Log::channel('polling')->error("PollCollectors: Dictionary '{$dictionaryCode}' not found");
                return;
            }

            $elements = $response->customDictionary->elements ?? [];
            if (empty($elements)) {
                Log::channel('polling')->info("PollCollectors: No elements found in dictionary '{$dictionaryCode}'");
                // Update sync state anyway
                $this->updateLastSync('collectors', now());
                return;
            }

            $this->info("Processing collectors, count: " . count($elements));
            Log::channel('polling')->info("PollCollectors: Found " . count($elements) . " collectors");

            // Pre-fetch metadata for collectors
            // Collectors (dictionary elements) use 'code' as ID in our converter.
            $ids = array_map(fn($e) => (string)($e['code'] ?? $e['id'] ?? ''), json_decode(json_encode($elements), true));
            // Entity type for collectors is 'user' (based on dispatch)
            $metadataMap = EventSyncMetadata::where('entity_type', 'user')
                ->whereIn('entity_id', $ids)
                ->get()
                ->keyBy('entity_id');

            foreach ($elements as $element) {
                $elementArr = json_decode(json_encode($element), true);
                $elementArr['__role'] = 'collector'; // Set role for converter
                $retailCrmId = (string)($elementArr['code'] ?? $elementArr['id'] ?? 'unknown');
                Log::channel('polling')->debug("PollCollectors: Processing element code: " . $retailCrmId, ['data' => $elementArr]);

                $metadata = $metadataMap[$retailCrmId] ?? null;
                // Use UserConverter to convert dictionary element to User entity structure
                $converted = $this->userConverter->fromRetailCRM($elementArr, $metadata);

                // Check for changes via hash (с учётом shop_code для мультишопных сущностей)
                if ($this->hashService->hasChanged('collectors', $converted['id'], $converted, $converted['shop_code'] ?? null)) {
                    $this->outbox->dispatch('user', 'user.updated', $converted, $converted['id']);
                    Log::channel('polling')->info("PollCollectors: Dispatched user ID: {$converted['id']} to outbox (CHANGED)");
                } else {
                    Log::channel('polling')->info("PollCollectors: Skipping user ID: {$converted['id']} (NO CHANGES)");
                }
            }

            $this->updateLastSync('collectors', now());
            Log::channel('polling')->info("PollCollectors: Sync completed");

        } catch (\Exception $e) {
            Log::channel('polling')->error("PollCollectors: Error syncing collectors: " . $e->getMessage());
            $this->error("PollCollectors: Error syncing collectors");
        }
    }

    protected function pollCouriers()
    {
        Log::channel('polling')->info("PollCouriers: Starting sync");

        try {
            $response = $this->retailService->getCouriers();

            if (!$response || empty($response->couriers)) {
                Log::channel('polling')->warning("PollCouriers: No couriers found or empty response");
                // Update sync state anyway to avoid repeated retries if it's just empty
                $this->updateLastSync('couriers', now());
                return;
            }

            $couriers = $response->couriers;
            $this->info("Processing couriers, count: " . count($couriers));
            Log::channel('polling')->info("PollCouriers: Found " . count($couriers) . " couriers");

            // Pre-fetch metadata for couriers
            $ids = array_map(fn($c) => (string)($c['id'] ?? ''), json_decode(json_encode($couriers), true));
            $metadataMap = EventSyncMetadata::where('entity_type', 'user')
                ->whereIn('entity_id', $ids)
                ->get()
                ->keyBy('entity_id');

            foreach ($couriers as $courier) {
                $courierArr = json_decode(json_encode($courier), true);
                $courierArr['__role'] = 'courier'; // Set role for converter
                $retailCrmId = (string)($courierArr['id'] ?? 'unknown');

                Log::channel('polling')->debug("PollCouriers: Processing courier ID: " . $retailCrmId, ['data' => $courierArr]);

                $metadata = $metadataMap[$retailCrmId] ?? null;
                // Use UserConverter to convert Courier entity to User entity structure
                $converted = $this->userConverter->fromRetailCRM($courierArr, $metadata);

                // Check for changes via hash (с учётом shop_code для мультишопных сущностей)
                if ($this->hashService->hasChanged('couriers', $converted['id'], $converted, $converted['shop_code'] ?? null)) {
                    $this->outbox->dispatch('user', 'user.updated', $converted, $converted['id']);
                    Log::channel('polling')->info("PollCouriers: Dispatched user ID: {$converted['id']} to outbox (CHANGED)");
                } else {
                    Log::channel('polling')->info("PollCouriers: Skipping user ID: {$converted['id']} (NO CHANGES)");
                }
            }

            $this->updateLastSync('couriers', now());
            Log::channel('polling')->info("PollCouriers: Sync completed");

        } catch (\Exception $e) {
            Log::channel('polling')->error("PollCouriers: Error syncing couriers: " . $e->getMessage());
            $this->error("PollCouriers: Error syncing couriers");
        }
    }


    protected function getLastSync($entity)
    {
        $state = SyncState::where('entity', $entity)->first();
        $date = $state ? $state->last_sync_at : null;
        Log::channel('polling')->info("getLastSync for {$entity}: " . ($date ? $date->format('Y-m-d H:i:s') : 'null'));
        return $date;
    }

    protected function updateLastSync($entity, $timestamp)
    {
        Log::channel('polling')->info("updateLastSync for {$entity}: " . $timestamp->format('Y-m-d H:i:s'));
        SyncState::updateOrCreate(
            ['entity' => $entity],
            ['last_sync_at' => $timestamp]
        );
        $this->info("Updated sync state for {$entity} to " . $timestamp->format('Y-m-d H:i:s'));
    }
}
