<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupDeletedProductFromCartsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $productId)
    {
    }

    public function handle(): void
    {
        $affectedUserIds = CartItem::query()
            ->where('product_id', $this->productId)
            ->join('carts', 'cart_items.cart_id', '=', 'carts.id')
            ->pluck('carts.user_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $deletedCount = CartItem::query()
            ->where('product_id', $this->productId)
            ->delete();

        Log::info('Product removed from carts', [
            'product_id' => $this->productId,
            'affected_users' => count($affectedUserIds),
            'removed_items' => $deletedCount,
        ]);

        foreach ($affectedUserIds as $userId) {
            dispatch(new NotifyClientProductUnavailableJob((int) $userId, $this->productId));
        }
    }
}
