<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Models\IntegrationConfig;
use Electrohub\DeliveryProviders\Services\RussianPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AddToRussianPostBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly string $resultId,
        public readonly ?int $shopId = null,
    ) {
    }

    public function handle(): void
    {
        if ($this->shopId !== null) {
            $shopId = $this->shopId;
        } else {
            // Резолвить shop через любую активную russian_post интеграцию
            $cfg = IntegrationConfig::where([
                'integration_type' => 'delivery_russian_post',
                'is_active' => true,
            ])->first();

            if (!$cfg) {
                Log::warning('AddToRussianPostBatchJob: no active russian_post integration', [
                    'result_id' => $this->resultId,
                ]);
                return;
            }

            $shopId = (int) $cfg->shop_id;
        }

        $svc = RussianPostService::forShop($shopId);
        $resp = $svc->addToDailyBatch([$this->resultId]);

        Log::info('AddToRussianPostBatchJob: added to batch', [
            'result_id' => $this->resultId,
            'shop_id' => $shopId,
            'response' => $resp,
        ]);
    }
}
