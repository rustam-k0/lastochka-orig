<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\Export\ProductExportQuery;
use App\Services\Export\ProductExportValuesResolver;
use App\Services\Export\ProductExportWriter;
use App\Support\SectionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Выгрузка товаров в Excel (xlsx) по аналогии с заказами
 * (purchase_back OrderExportController).
 *
 * POST /action/export/products
 * Тело: либо product_ids[] (выделенные строки — фильтры игнорируются),
 * либо набор фильтров экрана «Товары» — зеркало аргументов admin_products_paginate
 * (shop_id / available_in_shop_id, title, categories, status_id, is_culinary,
 * has_img, has_reviews, fill_percent_lt/gte, is_configurable, exclude_children,
 * only_children, ordering). selected_shop_id — магазин для цены/остатка строки.
 * Auth: Bearer-токен (guard api); доступ — как у admin_products_paginate:
 * policy onlyShop + раздел «Товары» при включённой матрице ролей.
 */
class ProductExportController extends Controller
{
    /** Колонки products, по которым разрешена сортировка выгрузки. */
    private const ORDER_COLUMNS = [
        'id',
        'title',
        'articul',
        'code',
        'status_id',
        'fill_percent',
        'shop_id',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private ProductExportQuery $query,
        private ProductExportValuesResolver $valuesResolver,
        private ProductExportWriter $writer
    ) {
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('onlyShop', Admin::class);

        if (!SectionAccess::allowed($request->user(), 'products')) {
            abort(403, 'Нет доступа к разделу: products');
        }

        $input = $request->validate([
            'product_ids' => 'sometimes|array',
            'product_ids.*' => 'integer',
            'shop_id' => 'sometimes|nullable|integer',
            'available_in_shop_id' => 'sometimes|nullable|integer',
            'selected_shop_id' => 'sometimes|nullable|integer',
            'title' => 'sometimes|nullable|string',
            'status_id' => 'sometimes|nullable|integer',
            'is_culinary' => 'sometimes|nullable|boolean',
            'is_new' => 'sometimes|nullable|boolean',
            'is_hurry_up' => 'sometimes|nullable|boolean',
            'categories' => 'sometimes|array',
            'categories.*' => 'integer',
            'is_configurable' => 'sometimes|nullable|boolean',
            'exclude_children' => 'sometimes|nullable|boolean',
            'only_children' => 'sometimes|nullable|boolean',
            'has_img' => 'sometimes|nullable|boolean',
            'has_reviews' => 'sometimes|nullable|boolean',
            'fill_percent_lt' => 'sometimes|nullable|integer',
            'fill_percent_gte' => 'sometimes|nullable|integer',
            'ordering' => 'sometimes|array',
            'ordering.*.orderColumn' => ['required', 'string', Rule::in(self::ORDER_COLUMNS)],
            'ordering.*.orderBy' => 'required|string|in:ASC,DESC,asc,desc',
        ]);

        $products = $this->query->build($input)->get();
        $selectedShopId = isset($input['selected_shop_id']) ? (int) $input['selected_shop_id'] : null;
        $values = $this->valuesResolver->resolve($products, $selectedShopId);

        $filename = $this->writer->filenamePrefix() . '_' . now()->format('Y-m-d_H-i-s') . '.xlsx';

        return response()->streamDownload(function () use ($products, $values) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $this->writer->write($writer, $products, $values);
            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
