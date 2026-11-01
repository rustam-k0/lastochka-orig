<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\StoreAccessDeniedException;
use App\Models\Product;
use App\Models\User;

final class BatchAddToCartAction
{
    public function __construct(
        private readonly AddToCartAction $addToCartAction,
    ) {
    }

    /**
     * Массовое добавление товаров в корзину.
     *
     * @param  array<array{productId: int, quantity: int}>  $items
     * @param  int|null  $storeId  магазин покупки для всех позиций (сетевой каталог)
     * @return array{added: int, skipped: int, skippedProducts: array<array{id: int, title: string, reason: string}>}
     */
    public function execute(User $user, array $items, ?int $storeId = null): array
    {
        $productIds = array_column($items, 'productId');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $added = 0;
        $skippedProducts = [];

        foreach ($items as $item) {
            $productId = $item['productId'];
            $quantity = $item['quantity'];
            $product = $products->get($productId);

            if (! $product) {
                $skippedProducts[] = [
                    'id' => $productId,
                    'title' => '',
                    'reason' => 'not_found',
                ];

                continue;
            }

            try {
                $this->addToCartAction->execute($user, $productId, $quantity, storeId: $storeId);
                $added++;
            } catch (InsufficientStockException) {
                $skippedProducts[] = [
                    'id' => $productId,
                    'title' => $product->title,
                    'reason' => 'out_of_stock',
                ];
            } catch (StoreAccessDeniedException) {
                $skippedProducts[] = [
                    'id' => $productId,
                    'title' => $product->title,
                    'reason' => 'store_access_denied',
                ];
            } catch (\Throwable) {
                $skippedProducts[] = [
                    'id' => $productId,
                    'title' => $product->title,
                    'reason' => 'unavailable',
                ];
            }
        }

        return [
            'added' => $added,
            'skipped' => count($skippedProducts),
            'skippedProducts' => $skippedProducts,
        ];
    }
}
