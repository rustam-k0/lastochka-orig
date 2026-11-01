<?php

declare(strict_types=1);

namespace App\Actions\Favorite;

use App\Exceptions\FavoriteLimitException;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;

final class ToggleFavoriteAction
{
    private const int MAX_FAVORITES = 500;

    /**
     * Переключить товар в избранном.
     *
     * @throws FavoriteLimitException
     */
    public function execute(User $user, Product $product): Product
    {
        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $count = Favorite::where('user_id', $user->id)->count();

            if ($count >= self::MAX_FAVORITES) {
                throw new FavoriteLimitException;
            }

            // createOrFirst: параллельный toggle (двойной тап) мог вставить ту же
            // строку после проверки выше — тогда UNIQUE не роняет запрос в 500.
            Favorite::query()->createOrFirst([
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);
        }

        return $product;
    }
}
