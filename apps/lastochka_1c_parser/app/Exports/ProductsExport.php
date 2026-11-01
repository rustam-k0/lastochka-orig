<?php

namespace App\Exports;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Exportable;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    private bool $activeOnly;
    private bool $inStockOnly;
    private ?int $limit;

    public function __construct(bool $activeOnly = false, bool $inStockOnly = false, ?int $limit = null)
    {
        $this->activeOnly = $activeOnly;
        $this->inStockOnly = $inStockOnly;
        $this->limit = $limit;
    }

    public function query(): Builder
    {
        $query = Product::query()
            ->with(['category', 'mainImage', 'stocks', 'retailPrice']);

        if ($this->activeOnly) {
            $query->active();
        }

        if ($this->inStockOnly) {
            $query->inStock();
        }

        // Исключаем категории из конфига (алкоголь и т.д.)
        $excludedCategories = config('export.excluded_categories', []);
        if (!empty($excludedCategories)) {
            $excludedIds = Category::getExcludedCategoryIds($excludedCategories);
            if (!empty($excludedIds)) {
                $query->whereNotIn('category_id', $excludedIds);
            }
        }

        // Ограничение количества записей
        if ($this->limit !== null && $this->limit > 0) {
            $query->limit($this->limit);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Название товара',
            'Товарная группа',
            'Описание',
            'Ссылка в магазине',
            'Ссылка на изображение',
            'Артикул',
            'Штрих-код',
            'Цена',
            'Количество',
            'Внешний ID',
            'Xml ID',
            'Длина',
            'Ширина',
            'Высота',
            'Вес',
        ];
    }

    public function map($product): array
    {
        return [
            $product->name,
            $product->category?->name ?? '',
            $product->description ?? '',
            $this->buildProductUrl($product),
            $this->buildImageUrl($product),
            $product->sku ?: "1C-{$product->id}",
            $product->barcode ?? '',
            $product->retailPrice?->value ?? 0,
            max(0, $product->stocks->sum('quantity')),
            $product->external_id,
            $product->external_id,
            $this->getAttributeValue($product, 'length'),
            $this->getAttributeValue($product, 'width'),
            $this->getAttributeValue($product, 'height'),
            $this->getAttributeValue($product, 'weight'),
        ];
    }

    public function chunkSize(): int
    {
        return config('export.chunk_size', 1000);
    }

    /**
     * Извлечь значение атрибута по маппингу
     */
    private function getAttributeValue(Product $product, string $key): string
    {
        $attributes = $product->attributes;

        if (empty($attributes)) {
            return '';
        }

        $possibleKeys = config("export.attributes_map.{$key}", []);

        foreach ($possibleKeys as $possibleKey) {
            if (isset($attributes[$possibleKey]) && $attributes[$possibleKey] !== '') {
                return (string) $attributes[$possibleKey];
            }
        }

        return '';
    }

    /**
     * Сформировать URL товара по шаблону
     */
    private function buildProductUrl(Product $product): string
    {
        // Without link
        return '';

        $template = config('export.product_url_template');

        if (empty($template) || empty($product->external_id)) {
            return '';
        }

        return str_replace('{external_id}', $product->external_id, $template);
    }

    /**
     * Сформировать полный URL изображения
     */
    private function buildImageUrl(Product $product): string
    {
        if (!$product->mainImage) {
            return '';
        }

        $baseUrl = config('export.image_base_url') ?: config('app.url');

        return rtrim($baseUrl, '/') . Storage::url($product->mainImage->path);
    }
}
