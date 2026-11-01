<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Собирает «проблемные» товары (без обязательных реквизитов) для диагностики
 * обмена 1С — count И список конкретных товаров за один проход, чтобы число
 * на странице «Проблемные товары» гарантированно совпадало с предупреждением
 * в модалке обмена (FOODLINK-11039).
 *
 * Источник истины — таблица products в БД 1c_parser. Списки уезжают в admin_back
 * через RabbitMQ-диагностику (ExchangeDiagnosticsPublisher), т.к. прямого доступа
 * к этой БД у админки нет.
 */
class ProblemProductsCollector
{
    /**
     * Максимум товаров на код в одном RabbitMQ-событии диагностики.
     *
     * Раньше было 5000 — на Lastochka это давало payload `1c_exchange_status`
     * по 0.5–1 МБ (тысячи товаров без изображения/единицы × 5 кодов × полная
     * карточка). Такое событие не помещалось в `sort_buffer_size` MySQL → outbox
     * publisher падал с `1038 Out of sort memory` → диагностика обмена вообще
     * переставала доходить до admin_back (статус «последний обмен» замерзал).
     *
     * 200 примеров на код достаточно для страницы «Проблемные товары» в админке;
     * точное число всегда передаётся отдельным полем `count`, а `truncated`
     * сигнализирует, что список усечён.
     */
    public const MAX_PER_CODE = 200;

    /**
     * Полный набор кодов, проверяемых для data_type, ВКЛЮЧАЯ нулевые.
     * Нужен admin_back для replace-snapshot: чтобы «починенный» код (стал 0)
     * вычистил свои старые строки, даже если в problem_products его уже нет.
     *
     * @return string[]
     */
    public static function codesForDataType(string $dataType): array
    {
        return match ($dataType) {
            ExchangeLog::TYPE_CATALOG_IMPORT => [
                'missing_name',
                'missing_sku',
                'missing_barcode',
                'missing_unit',
                'missing_image',
            ],
            ExchangeLog::TYPE_OFFERS_IMPORT => [
                'missing_price',
                'missing_stock',
            ],
            default => [],
        };
    }

    /**
     * Собрать проблемы по data_type. Возвращает только коды с count > 0.
     *
     * @return array<int, array{code:string, count:int, message:string, products:array, truncated:bool}>
     */
    public static function collect(string $dataType): array
    {
        $entries = match ($dataType) {
            ExchangeLog::TYPE_CATALOG_IMPORT => self::collectCatalog(),
            ExchangeLog::TYPE_OFFERS_IMPORT => self::collectOffers(),
            default => [],
        };

        return array_values(array_filter($entries));
    }

    /**
     * @return array<int, array|null>
     */
    protected static function collectCatalog(): array
    {
        return [
            self::buildEntry('missing_name', 'товаров без наименования', self::active()
                ->where(function (Builder $q) {
                    $q->whereNull('name')->orWhere('name', '');
                })),

            self::buildEntry('missing_sku', 'товаров без артикула', self::active()
                ->where(function (Builder $q) {
                    $q->whereNull('sku')->orWhere('sku', '');
                })),

            self::buildEntry('missing_barcode', 'товаров без штрихкода', self::active()
                ->where(function (Builder $q) {
                    $q->whereNull('barcode')->orWhere('barcode', '');
                })
                ->where(function (Builder $q) {
                    $q->whereNull('barcodes')->orWhereJsonLength('barcodes', 0);
                })),

            self::buildEntry('missing_unit', 'товаров без единицы измерения', self::active()
                ->where(function (Builder $q) {
                    $q->whereNull('attributes')
                        ->orWhere('attributes->unit', null)
                        ->orWhere('attributes->unit', '');
                })),

            self::buildEntry('missing_image', 'товаров без изображения', self::active()
                ->whereDoesntHave('images')),
        ];
    }

    /**
     * @return array<int, array|null>
     */
    protected static function collectOffers(): array
    {
        return [
            self::buildEntry('missing_price', 'товаров без цены', self::active()
                ->whereDoesntHave('prices')),

            self::buildEntry('missing_stock', 'товаров без остатка', self::active()
                ->whereDoesntHave('stocks')),
        ];
    }

    protected static function active(): Builder
    {
        return Product::query()->where('is_active', true);
    }

    /**
     * @return array{code:string, count:int, message:string, products:array, truncated:bool}|null
     */
    protected static function buildEntry(string $code, string $suffix, Builder $query): ?array
    {
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        $rows = (clone $query)
            ->select('external_id', 'sku', 'name', 'barcode', 'barcodes')
            ->limit(self::MAX_PER_CODE)
            ->get();

        $products = $rows->map(fn (Product $p) => [
            'external_id' => $p->external_id,
            'sku' => $p->sku,
            'name' => $p->name,
            'barcode' => self::firstBarcode($p),
        ])->all();

        return [
            'code' => $code,
            'count' => $count,
            'message' => "{$count} {$suffix}",
            'products' => $products,
            'truncated' => $count > self::MAX_PER_CODE,
        ];
    }

    protected static function firstBarcode(Product $p): ?string
    {
        if (!empty($p->barcode)) {
            return (string) $p->barcode;
        }

        if (is_array($p->barcodes) && count($p->barcodes) > 0) {
            return (string) $p->barcodes[0];
        }

        return null;
    }
}
