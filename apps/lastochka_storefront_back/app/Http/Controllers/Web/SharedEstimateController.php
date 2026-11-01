<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\Estimate;
use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Http\Response;

final class SharedEstimateController extends Controller
{
    /**
     * Публичная страница сметы по share-токену.
     *
     * Целевой адрес универсальной ссылки (/estimates/shared/*): у кого
     * установлено приложение — iOS/Android откроют URL в приложении,
     * у остальных отрисуется эта страница с составом сметы и ссылками на сторы.
     */
    public function __invoke(string $token): Response
    {
        $estimate = Estimate::where('share_token', $token)
            ->with(['items.product.images'])
            ->first();

        $apps = [
            'appstoreUrl' => (string) config('deeplinks.apps.appstore_url'),
            'googlePlayUrl' => (string) config('deeplinks.apps.google_play_url'),
            'appUrl' => $this->appUrl($token),
            'appstoreId' => $this->appstoreId(),
        ];

        if (! $estimate) {
            return response()->view('estimates.shared', $apps + ['estimate' => null], 404);
        }

        $resolver = app(ProductPriceResolver::class);
        $resolver->preloadProducts($estimate->items->map(fn ($item) => $item->product)->filter());

        $items = $estimate->items->map(function ($item) use ($resolver) {
            $product = $item->product;
            // Цены от количества (MID-308): цена позиции сметы по её количеству.
            $price = $product ? $resolver->priceForQuantity($product, (int) $item->quantity) : 0;
            $image = $product?->images->first();

            return [
                'sku' => $product->sku ?? '',
                'title' => $product->title ?? 'Товар недоступен',
                'quantity' => $item->quantity,
                'price' => $price,
                'sum' => $product ? $resolver->lineTotalForQuantity($product, (int) $item->quantity) : 0,
                'image' => $image ? ImageResource::toFullUrl((string) $image->path) : null,
            ];
        });

        return response()->view('estimates.shared', $apps + [
            'estimate' => $estimate,
            'items' => $items,
            'total' => $items->sum('sum'),
        ]);
    }

    /**
     * Диплинк на смету через кастомную URL-схему приложения.
     *
     * Кнопке «Открыть в приложении» нельзя дать universal link на самих себя:
     * переход на тот же домен из браузера iOS в приложение не уводит.
     */
    private function appUrl(string $token): ?string
    {
        $scheme = (string) config('deeplinks.apps.scheme');

        return $scheme !== '' ? "{$scheme}://estimates/shared/{$token}" : null;
    }

    /**
     * Числовой id приложения из URL App Store — для Smart App Banner в Safari.
     */
    private function appstoreId(): ?string
    {
        if (preg_match('~/id(\d+)~', (string) config('deeplinks.apps.appstore_url'), $m)) {
            return $m[1];
        }

        return null;
    }
}
