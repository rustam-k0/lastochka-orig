<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;

class ResetFixedStockCommand extends Command
{
    protected $signature = 'stock:reset-fixed';
    protected $description = 'Сбросить остаток товаров с фиксированным дневным лимитом на заданное число (начало дня)';

    public function handle(SearchService $searchService): int
    {
        $affected = 0;

        // Обходим товары поштучно вместо массового UPDATE: он не поднимает
        // модели, поэтому документы в Meilisearch оставались с in_stock=false
        // и товар не находился поиском даже после сброса лимита.
        Product::query()
            ->where('stock_mode', 'fixed')
            ->whereNotNull('fixed_stock')
            ->chunkById(200, function ($products) use (&$affected, $searchService): void {
                foreach ($products as $product) {
                    $product->quantity = (int) $product->fixed_stock;
                    $product->save();
                    $affected++;

                    // Явная переиндексация: EventConsumer и часть путей сохраняют
                    // модель через withoutEvents(), полагаться на ProductObserver
                    // здесь нельзя.
                    if ($product->is_active) {
                        $searchService->indexProduct($product);
                    }
                }
            });

        $this->info("Сброшен дневной остаток: {$affected} товаров.");

        return self::SUCCESS;
    }
}
