<?php

namespace App\Jobs\MerchantMultiShop;

use App\Services\MerchantBannerService;
use App\Services\MerchantMultiShop\MultiShopCreateService;
use App\Services\MerchantProductGroupService;
use App\Services\MerchantSaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Фоновое создание копий merchant-сущности в остальных магазинах после
 * синхронного создания primary-записи в мутации (MID-237/238/229).
 *
 * Каждая копия создаётся в собственной транзакции: outbox-события публикует
 * пакет per запись, упавший магазин не откатывает уже созданные копии.
 * Джоба идемпотентна к ретраям — существующая копия (shop_id + title
 * [+ период у акции]) пропускается.
 */
class CreateMerchantEntityCopiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ENTITY_SALE = 'merchant_sale';
    public const ENTITY_BANNER = 'merchant_banner';
    public const ENTITY_PRODUCT_GROUP = 'merchant_product_group';

    public int $timeout = 3600;
    public int $tries = 3;

    public function __construct(
        public string $entity,
        public array $args,
        public array $shopIds,
        public bool $allShops,
        public int $excludeShopId,
    ) {
    }

    public function handle(): void
    {
        $targets = $this->allShops
            ? MultiShopCreateService::allTargetShopIds($this->excludeShopId)
            : array_values(array_diff($this->shopIds, [$this->excludeShopId]));

        $created = 0;
        $skipped = 0;

        foreach ($targets as $shopId) {
            if ($this->copyExists((int) $shopId)) {
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($shopId) {
                    $this->createForShop((int) $shopId);
                });
                $created++;
            } catch (\Throwable $e) {
                Log::error('Merchant multi-shop copy failed', [
                    'entity' => $this->entity,
                    'shop_id' => $shopId,
                    'title' => $this->args['title'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        Log::info('Merchant multi-shop copies created', [
            'entity' => $this->entity,
            'title' => $this->args['title'] ?? null,
            'all_shops' => $this->allShops,
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    private function createForShop(int $shopId): void
    {
        match ($this->entity) {
            self::ENTITY_SALE => (new MerchantSaleService())->createForShop($this->args, $shopId),
            self::ENTITY_BANNER => (new MerchantBannerService())->createForShop($this->args, $shopId),
            self::ENTITY_PRODUCT_GROUP => (new MerchantProductGroupService())->createForShop($this->args, $shopId),
            default => throw new \InvalidArgumentException("Unknown merchant entity: {$this->entity}"),
        };
    }

    private function copyExists(int $shopId): bool
    {
        $title = (string) ($this->args['title'] ?? '');

        return match ($this->entity) {
            self::ENTITY_SALE => \App\Models\MerchantSale::query()
                ->where('shop_id', $shopId)
                ->where('title', $title)
                ->where('starts_at', $this->args['starts_at'] ?? null)
                ->where('ends_at', $this->args['ends_at'] ?? null)
                ->exists(),
            self::ENTITY_BANNER => \App\Models\MerchantBanner::query()
                ->where('shop_id', $shopId)
                ->where('title', $title)
                ->exists(),
            self::ENTITY_PRODUCT_GROUP => \App\Models\MerchantProductGroup::query()
                ->where('shop_id', $shopId)
                ->where('title', $title)
                ->exists(),
            default => false,
        };
    }
}
