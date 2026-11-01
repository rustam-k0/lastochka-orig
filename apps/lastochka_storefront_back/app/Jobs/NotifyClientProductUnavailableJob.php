<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyClientProductUnavailableJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $userId, public int $productId)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $product = Product::withTrashed()->find($this->productId);
        $title = $product?->title ?? ('#' . $this->productId);

        // MVP: только лог. Полноценная нотификация (OneSignal/email/in-app) — отдельной итерацией.
        Log::info('Product removed from user cart', [
            'user_id' => $this->userId,
            'product_id' => $this->productId,
            'title' => $title,
        ]);
    }
}
