<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DeleteOrdersCommand extends Command
{
    protected $signature = 'orders:delete
        {--all : Удалить все заказы}
        {--order-id= : Удалить конкретный заказ по ID}
        {--user-id= : Удалить все заказы пользователя}
        {--force : Пропустить подтверждение}';

    protected $description = 'Удаление заказов и всех связанных данных (items, addresses, promocodes, event_sync_metadata)';

    public function handle(): int
    {
        $query = Order::withTrashed();

        if ($orderId = $this->option('order-id')) {
            $query->where('id', $orderId);
        } elseif ($userId = $this->option('user-id')) {
            $query->where('user_id', $userId);
        } elseif (! $this->option('all')) {
            $this->error('Укажите --all, --order-id= или --user-id=');

            return self::FAILURE;
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('Заказы не найдены.');

            return self::SUCCESS;
        }

        $this->info("Найдено заказов для удаления: {$count}");

        if (! $this->option('force') && ! $this->confirm('Удалить заказы и все связанные данные? Это действие необратимо.')) {
            $this->info('Отменено.');

            return self::SUCCESS;
        }

        $orderIds = $query->pluck('id');

        DB::transaction(function () use ($orderIds): void {
            $metadataDeleted = DB::table('event_sync_metadata')
                ->where('entity_type', 'orders')
                ->whereIn('entity_id', $orderIds)
                ->delete();

            $this->info("Удалено записей event_sync_metadata: {$metadataDeleted}");

            $ordersDeleted = Order::withTrashed()
                ->whereIn('id', $orderIds)
                ->forceDelete();

            $this->info("Удалено заказов: {$ordersDeleted}");
        });

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
