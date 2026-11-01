<?php

namespace App\Console\Commands;

use App\Models\ExchangeLog;
use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Ручной экспорт заказов в 1С
 *
 * php artisan 1c:export-orders
 * php artisan 1c:export-orders --output=/path/to/orders.xml
 */
class ExportOrders1CCommand extends Command
{
    protected $signature = '1c:export-orders
                            {--output= : Путь для сохранения XML (опционально)}
                            {--dry-run : Только показать XML, не помечать заказы как экспортированные}';

    protected $description = 'Экспорт заказов в формате CommerceML';

    public function handle(): int
    {
        $orders = Order::forExport()->with('items')->get();

        if ($orders->isEmpty()) {
            $this->info('Нет заказов для экспорта.');
            return self::SUCCESS;
        }

        $this->info("Найдено заказов для экспорта: {$orders->count()}");

        $handler = new OrderExportHandler();
        $xml = $handler->getXML();

        $outputPath = $this->option('output');
        $isDryRun = $this->option('dry-run');

        if ($outputPath) {
            file_put_contents($outputPath, $xml);
            $this->info("XML сохранен в: {$outputPath}");
        } else {
            // Сохраняем в storage
            $fileName = 'orders_' . now()->format('Y-m-d_His') . '.xml';
            Storage::put("1c-export/{$fileName}", $xml);
            $this->info("XML сохранен в: storage/app/1c-export/{$fileName}");
        }

        if ($isDryRun) {
            $this->warn('Dry-run режим: заказы НЕ помечены как экспортированные');
            $this->line('');
            $this->line($xml);
        } else {
            $handler->stepSuccess();
            $this->info('Заказы помечены как экспортированные.');
        }

        return self::SUCCESS;
    }
}
