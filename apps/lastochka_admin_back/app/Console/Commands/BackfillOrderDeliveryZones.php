<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderDeliveryZoneSync;
use Illuminate\Console\Command;

class BackfillOrderDeliveryZones extends Command
{
    protected $signature = 'orders:backfill-delivery-zones {--chunk=500}';

    protected $description = 'Пересчитать orders.zone_id (зона доставки) по всем родительским заказам. Идемпотентно.';

    public function handle(): int
    {
        $sync = new OrderDeliveryZoneSync();
        $processed = 0;

        Order::query()
            ->whereNull('parent_order_id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use ($sync, &$processed) {
                foreach ($orders as $order) {
                    $sync->sync($order);
                    $processed++;
                }
                $this->output->write('.');
            });

        $this->newLine();
        $this->info("Обработано заказов: {$processed}");

        return Command::SUCCESS;
    }
}
