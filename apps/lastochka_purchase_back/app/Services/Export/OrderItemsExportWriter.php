<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Type;
use App\Support\StockUnits;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Лист «строка = позиция заказа» — исторический формат выгрузки.
 */
class OrderItemsExportWriter
{
    private const HEADERS = [
        '№ заказа',
        'Дата',
        'Покупатель',
        'Телефон',
        'Статус',
        'Сумма заказа',
        'Магазин',
        'Тип оплаты',
        'Статус оплаты',
        'Адрес доставки',
        'Слот доставки',
        'Товар',
        'Количество',
        'Цена за ед.',
        'Сумма позиции',
        'Сборщик',
        'Курьер',
        // В конце, чтобы не сдвигать колонки старых шаблонов выгрузки.
        'Ед. изм.',
    ];

    public function __construct(private OrderLabels $labels)
    {
    }

    public function title(): string
    {
        return 'Заказы';
    }

    public function filenamePrefix(): string
    {
        return 'orders_export';
    }

    /**
     * @param Collection<int, Order> $orders
     * @param array<int, array{collector: ?object, couriers: array<int, object>}> $staff
     */
    public function write(Worksheet $sheet, Collection $orders, array $staff): void
    {
        $columns = ExportColumns::letters(count(self::HEADERS));

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue($columns[$index] . '1', $header);
        }

        $sheet->getStyle('A1:' . end($columns) . '1')->getFont()->setBold(true);

        $row = 2;

        foreach ($orders as $order) {
            $orderStaff = $staff[$order->id] ?? ['collector' => null, 'couriers' => []];
            $items = $order->orderItems ?? collect();

            if ($items->isEmpty()) {
                $this->writeRow($sheet, $row, $order, $orderStaff);
                $row++;
                continue;
            }

            foreach ($items as $item) {
                $this->writeRow($sheet, $row, $order, $orderStaff, $item);
                $row++;
            }
        }

        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function writeRow(
        Worksheet $sheet,
        int $row,
        Order $order,
        array $orderStaff,
        ?OrderItem $item = null
    ): void {
        $couriers = array_map(
            fn ($courier) => $this->labels->staffName($courier),
            $orderStaff['couriers']
        );

        $sheet->setCellValue('A' . $row, $order->number);
        $sheet->setCellValue('B' . $row, $order->created_at?->format('d.m.Y H:i') ?? '');
        $sheet->setCellValue('C' . $row, $this->labels->customerName($order));
        $sheet->setCellValue('D' . $row, $this->labels->customerPhone($order));
        // Лист по товарам печатает те же подписи статуса, что и таблица
        // «Заказы» в админке (в т.ч. «Выдан» для завершённого самовывоза).
        // hasCourier берётся из батчевого OrderStaffResolver.
        $sheet->setCellValue('E' . $row, $this->labels->status($order, (bool) $couriers));
        $sheet->setCellValue('F' . $row, $order->amount);
        $sheet->setCellValue('G' . $row, $order->shop?->title ?? '');
        $sheet->setCellValue('H' . $row, $this->labels->paymentType($order->payment_type_id));
        $sheet->setCellValue('I' . $row, $this->labels->paidFlag($order));
        $sheet->setCellValue('J' . $row, $order->clientInfo?->address ?? '');
        $sheet->setCellValue('K' . $row, $this->labels->slot($order));

        if ($item) {
            $sheet->setCellValue('L' . $row, $item->product?->title ?: 'Товар недоступен');
            $sheet->setCellValue('M' . $row, $this->displayQuantity($item));
            $sheet->setCellValue('N' . $row, $item->price_during_order);
            $sheet->setCellValue('O' . $row, $this->lineTotal($item));
            $sheet->setCellValue('R' . $row, $this->unitLabel($item));
        }

        $sheet->setCellValue('P' . $row, $this->labels->staffName($orderStaff['collector']));
        $sheet->setCellValue('Q' . $row, implode(', ', $couriers));
    }

    /**
     * Количество в единицах товара (г/мл/шт), а не в порциях: «3» у филе по
     * 300 г — это 900 г. После сборки весового — фактический вес.
     */
    private function displayQuantity(OrderItem $item): int|float
    {
        $product = $item->product;

        if (!$product) {
            return (int) $item->quantity;
        }

        if ($item->actual_weight !== null && $this->isWeightBased($item)) {
            return (int) $item->actual_weight;
        }

        return (int) $item->quantity * StockUnits::portionInSmallest($product);
    }

    /**
     * Итог строки. order_items.price до AssemblyFinish — цена за порцию, после —
     * итог строки; маркер записанного итога — actual_weight (как lineTotal
     * в OrderEventTransformer).
     */
    private function lineTotal(OrderItem $item): float
    {
        if ($item->actual_weight !== null) {
            return round((float) $item->price, 2);
        }

        $unitPrice = (float) ($item->price_during_order ?? $item->price);

        return round($unitPrice * (int) $item->quantity, 2);
    }

    private function unitLabel(OrderItem $item): string
    {
        return match ((int) $item->product?->info_weight_type_id) {
            Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM => 'г',
            Type::INFO_WEIGHT_LITER, Type::INFO_WEIGHT_M_LITER => 'мл',
            default => 'шт',
        };
    }

    private function isWeightBased(OrderItem $item): bool
    {
        return in_array((int) $item->product?->info_weight_type_id, [
            Type::INFO_WEIGHT_GRAM,
            Type::INFO_WEIGHT_KILOGRAM,
            Type::INFO_WEIGHT_LITER,
            Type::INFO_WEIGHT_M_LITER,
        ], true);
    }
}
