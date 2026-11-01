<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Services\Search\SearchService;

final class ProductObserver
{
    public function __construct(
        private readonly SearchService $searchService
    ) {}

    /**
     * При создании товара — индексируем если активен.
     */
    public function created(Product $product): void
    {
        if ($product->is_active) {
            $this->searchService->indexProduct($product);
        }

        // Переиндексировать parent при изменении child
        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }

    /**
     * При обновлении товара — обновляем индекс.
     * Если товар стал неактивным — удаляем из индекса.
     */
    public function updated(Product $product): void
    {
        if ($product->is_active) {
            $this->searchService->indexProduct($product);
        } else {
            $this->searchService->removeProduct($product->id);
        }

        // Переиндексировать parent при изменении child
        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }

    /**
     * При удалении товара — удаляем из индекса.
     */
    public function deleted(Product $product): void
    {
        $this->searchService->removeProduct($product->id);

        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }

    /**
     * При восстановлении товара (soft delete) — индексируем если активен.
     */
    public function restored(Product $product): void
    {
        if ($product->is_active) {
            $this->searchService->indexProduct($product);
        }
    }

    /**
     * При полном удалении товара — удаляем из индекса.
     */
    public function forceDeleted(Product $product): void
    {
        $this->searchService->removeProduct($product->id);

        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }
}
