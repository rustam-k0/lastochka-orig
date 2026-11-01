<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Сводный лист выгрузки: строка = заказ, без разбивки по товарам.
 * Состав колонок повторяет таблицу «Заказы» в админке.
 */
class OrderSummaryExportWriter
{
    private const HEADERS = [
        '№ заказа',
        'Дата',
        'Слот доставки',
        'Покупатель',
        'Телефон',
        'Статус',
        'Магазин',
        'Тип',
        'Способ оплаты',
        'Статус оплаты',
        'Сумма заказа',
        'Адрес доставки',
        'Сборщик',
        'Курьер',
    ];

    private const HEAVY_HEADER = 'Тяжёлый';

    public function __construct(private OrderLabels $labels)
    {
    }

    public function title(): string
    {
        return 'Заказы';
    }

    public function filenamePrefix(): string
    {
        return 'orders_summary';
    }

    /**
     * @param Collection<int, Order> $orders
     * @param array<int, array{collector: ?object, couriers: array<int, object>}> $staff
     */
    public function write(Worksheet $sheet, Collection $orders, array $staff): void
    {
        // Колонка «Тяжёлый» есть только когда включён соответствующий флоу —
        // так же, как колонка скрывается в таблице заказов админки
        $withHeavy = Order::isHeavyOrdersFlowEnabled();
        $headers = self::HEADERS;

        if ($withHeavy) {
            $headers[] = self::HEAVY_HEADER;
        }

        $columns = ExportColumns::letters(count($headers));

        foreach ($headers as $index => $header) {
            $sheet->setCellValue($columns[$index] . '1', $header);
        }

        $sheet->getStyle('A1:' . end($columns) . '1')->getFont()->setBold(true);

        $row = 2;

        foreach ($orders as $order) {
            $orderStaff = $staff[$order->id] ?? ['collector' => null, 'couriers' => []];
            $couriers = array_map(
                fn ($courier) => $this->labels->staffName($courier),
                $orderStaff['couriers']
            );

            $sheet->setCellValue('A' . $row, $order->number);
            $sheet->setCellValue('B' . $row, $order->created_at?->format('d.m.Y H:i') ?? '');
            $sheet->setCellValue('C' . $row, $this->labels->slot($order));
            $sheet->setCellValue('D' . $row, $this->labels->customerName($order));
            $sheet->setCellValue('E' . $row, $this->labels->customerPhone($order));
            $sheet->setCellValue('F' . $row, $this->labels->status($order, (bool) $couriers));
            $sheet->setCellValue('G' . $row, $order->shop?->title ?? '');
            $sheet->setCellValue('H' . $row, $this->labels->deliveryType($order->type_id));
            $sheet->setCellValue('I' . $row, $this->labels->paymentType($order->payment_type_id));
            $sheet->setCellValue('J' . $row, $this->labels->paymentStatus($order->payment_status));
            $sheet->setCellValue('K' . $row, $order->amount);
            $sheet->setCellValue('L' . $row, $order->clientInfo?->address ?? '');
            $sheet->setCellValue('M' . $row, $this->labels->staffName($orderStaff['collector']));
            $sheet->setCellValue('N' . $row, implode(', ', $couriers));

            if ($withHeavy) {
                $sheet->setCellValue('O' . $row, $order->is_heavy ? 'Да' : '');
            }

            $row++;
        }

        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
