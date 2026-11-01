<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Services\Quantum\UnitFamily;
use App\Support\StockUnits;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Лист выгрузки товаров: строка = товар.
 * Состав колонок повторяет таблицу «Товары» в админке плюс идентификаторы
 * (артикул, штрихкод, код 1С) и магазин-владелец карточки.
 */
class ProductExportWriter
{
    public const HEADERS = [
        'ID',
        'Артикул',
        'Штрихкод',
        'Код 1С',
        'Название',
        'Категории',
        'Магазин',
        'Стоимость',
        'Остатки',
        'Фиксированные остатки',
        'Заполненность, %',
        'Статус',
        'Дата создания',
        // В конце, чтобы не сдвигать колонки старых шаблонов: «Остатки» — в
        // базовой единице 1С (кг/л/шт), «Фиксированные остатки» — в наименьшей (г/мл/шт).
        'Ед. остатка',
        'Ед. фикс. остатков',
    ];

    /** Ширины колонок (в символах) в порядке HEADERS. */
    private const COLUMN_WIDTHS = [10, 16, 18, 16, 48, 32, 28, 12, 12, 22, 16, 16, 18, 12, 18];

    private const UNIT_LABELS = [
        Type::INFO_WEIGHT_PIECE => 'шт',
        Type::INFO_WEIGHT_GRAM => 'г',
        Type::INFO_WEIGHT_KILOGRAM => 'кг',
        Type::INFO_WEIGHT_LITER => 'л',
        Type::INFO_WEIGHT_M_LITER => 'мл',
    ];

    private const SMALLEST_UNIT_LABELS = [
        UnitFamily::MASS => 'г',
        UnitFamily::VOLUME => 'мл',
        UnitFamily::PIECE => 'шт',
    ];

    private const STATUS_LABELS = [
        Status::CODE_ACTIVE => 'Активен',
        Status::CODE_NOT_PUBLISHED => 'Не опубликован',
    ];

    public function title(): string
    {
        return 'Товары';
    }

    public function filenamePrefix(): string
    {
        return 'products';
    }

    /**
     * Пишет заголовок и строки в открытый writer (php://output).
     *
     * @param Collection<int, Product> $products
     * @param array{prices: array<int, float>, remains: array<int, float>, shops: array<int, string>} $values
     */
    public function write(Writer $writer, Collection $products, array $values): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($this->title());

        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $sheet->setColumnWidth((float) $width, $index + 1);
        }

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style())->setFontBold()));

        foreach ($products as $product) {
            $writer->addRow(Row::fromValues($this->row($product, $values)));
        }
    }

    /**
     * @param array{prices: array<int, float>, remains: array<int, float>, shops: array<int, string>} $values
     * @return array<int, int|float|string>
     */
    private function row(Product $product, array $values): array
    {
        $id = (int) $product->id;

        return [
            $id,
            // Артикул, штрихкод и код 1С — строками, чтобы Excel не превращал их в экспоненту
            (string) ($product->articul ?? ''),
            $this->barcode($product),
            (string) ($product->code ?? ''),
            (string) ($product->title ?? ''),
            $product->categories->pluck('title')->filter()->implode(', '),
            $values['shops'][(int) $product->shop_id] ?? '',
            // Стоимость без строки цены — пусто (как «-» в таблице), остаток — 0 (как remains_sum)
            $values['prices'][$id] ?? '',
            $values['remains'][$id] ?? 0,
            $this->fixedStock($product),
            (int) ($product->fill_percent ?? 0),
            self::STATUS_LABELS[(int) $product->status_id] ?? (string) ($product->status_id ?? ''),
            $product->created_at?->format('d.m.Y H:i') ?? '',
            self::UNIT_LABELS[StockUnits::stockUnit($product)] ?? 'шт',
            $this->fixedStock($product) === '' ? '' : (self::SMALLEST_UNIT_LABELS[UnitFamily::family(StockUnits::stockUnit($product))] ?? 'шт'),
        ];
    }

    /**
     * Все штрихкоды товара через запятую: barcodes — надмножество barcode
     * (бэкфилл миграции), одиночный barcode — fallback для старых карточек.
     */
    private function barcode(Product $product): string
    {
        $barcodes = array_values(array_filter(
            array_map('strval', (array) ($product->barcodes ?? [])),
            static fn (string $barcode) => $barcode !== ''
        ));

        if ($barcodes !== []) {
            return implode(', ', $barcodes);
        }

        return (string) ($product->barcode ?? '');
    }

    /**
     * Как колонка «Фиксированные остатки» в таблице админки: значение
     * только когда эффективный режим остатков — fixed.
     */
    private function fixedStock(Product $product): int|string
    {
        if ($product->effective_stock_mode !== 'fixed' || $product->effective_fixed_stock === null) {
            return '';
        }

        return (int) $product->effective_fixed_stock;
    }
}
