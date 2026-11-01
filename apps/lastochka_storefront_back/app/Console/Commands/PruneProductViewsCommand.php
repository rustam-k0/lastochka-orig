<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductView;
use Illuminate\Console\Command;

/**
 * Удаляет просмотры старше personal_view_ttl_days: в «Смотрел, но не купил»
 * они уже не попадают, а таблица иначе растёт бесконечно.
 */
class PruneProductViewsCommand extends Command
{
    protected $signature = 'product-views:prune';

    protected $description = 'Удалить просмотры карточек старше personal_view_ttl_days (группа «Смотрел, но не купил»)';

    public function handle(): int
    {
        $days = (int) config('product_groups.personal_view_ttl_days', 14);
        $before = now()->subDays($days);
        $deleted = 0;

        // Порциями: одна большая DELETE держала бы блокировки на всей таблице.
        do {
            $ids = ProductView::query()
                ->where('last_viewed_at', '<', $before)
                ->orWhereNull('last_viewed_at')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += ProductView::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Удалено просмотров: {$deleted} (старше {$days} дн.)");

        return self::SUCCESS;
    }
}
