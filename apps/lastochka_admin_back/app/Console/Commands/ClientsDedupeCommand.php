<?php

namespace App\Console\Commands;

use App\Models\Client;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Команда дедупа клиентов по номеру телефона.
 *
 * Исторические данные: в lastochka_admin_back нет UNIQUE INDEX на clients.phone,
 * и race condition в RabbitMQ-консюмере породил дубли (см. ClientEventTransformer).
 *
 * Стратегия:
 *   1. Группируем активных клиентов по phone.
 *   2. Для каждой группы > 1 выбираем master: больше всего заказов → последний updated_at → минимальный id.
 *   3. Переносим все FK (12 таблиц) на master_id в одной транзакции.
 *   4. Soft-delete остальные дубли.
 *   5. Подтягиваем event_sync_metadata: дубли → master.
 *
 * По умолчанию работает в dry-run режиме. Для реального запуска — флаг --apply.
 */
class ClientsDedupeCommand extends Command
{
    protected $signature = 'clients:dedupe
        {--apply : Применить изменения (по умолчанию dry-run)}
        {--phone= : Обработать только указанный номер телефона}';

    protected $description = 'Слить дубли клиентов по phone, перенеся FK на master-запись';

    /**
     * Таблицы с client_id, на которые нужно перенести FK от дубля к master.
     */
    private const FK_TABLES = [
        'carts',
        'client_card_orders',
        'client_cards',
        'client_delivery_infos',
        'clients_promos',
        'orders',
        'preorders',
        'product_favourites',
        'product_issue_reports',
        'reviews',
        'shop_favourites',
        'used_promos',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyPhone = $this->option('phone');

        $this->info($apply ? '=== РЕЖИМ APPLY — изменения будут применены ===' : '=== DRY-RUN — изменения НЕ применяются ===');

        $groups = $this->findDuplicateGroups($onlyPhone);

        if ($groups->isEmpty()) {
            $this->info('Дубли не найдены. Нечего делать.');
            return self::SUCCESS;
        }

        $this->info("Групп-дублей: {$groups->count()}");

        $totalMerged = 0;
        $totalMovedRows = 0;

        foreach ($groups as $group) {
            $result = $this->processGroup($group->phone, $apply);
            $totalMerged += $result['merged_count'];
            $totalMovedRows += $result['moved_rows'];
        }

        $this->newLine();
        $this->info("ИТОГО: удалено дублей: {$totalMerged}, перенесено строк: {$totalMovedRows}");

        if (!$apply) {
            $this->warn('Это был dry-run. Для реального применения: --apply');
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{phone: string, cnt: int}>
     */
    private function findDuplicateGroups(?string $onlyPhone)
    {
        $query = Client::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('phone')
            ->select('phone', DB::raw('COUNT(*) as cnt'))
            ->groupBy('phone')
            ->having('cnt', '>', 1);

        if ($onlyPhone) {
            $query->where('phone', $onlyPhone);
        }

        return $query->get();
    }

    /**
     * @return array{merged_count:int, moved_rows:int}
     */
    private function processGroup(string $phone, bool $apply): array
    {
        $clients = Client::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('phone', $phone)
            ->get();

        if ($clients->count() < 2) {
            return ['merged_count' => 0, 'moved_rows' => 0];
        }

        $master = $this->pickMaster($clients);
        $duplicates = $clients->reject(fn (Client $c) => $c->id === $master->id);

        $this->line(sprintf(
            '  phone=%s  master=%d  duplicates=[%s]',
            $phone,
            $master->id,
            $duplicates->pluck('id')->join(',')
        ));

        $presentTables = array_values(array_filter(
            self::FK_TABLES,
            fn (string $table) => Schema::hasTable($table)
        ));

        if (!$apply) {
            $movedRows = 0;
            foreach ($duplicates as $dup) {
                foreach ($presentTables as $table) {
                    $movedRows += DB::table($table)->where('client_id', $dup->id)->count();
                }
            }
            $this->line("    (dry-run) будет перенесено строк: {$movedRows}");
            return ['merged_count' => $duplicates->count(), 'moved_rows' => $movedRows];
        }

        $movedRows = 0;

        DB::transaction(function () use ($master, $duplicates, $presentTables, &$movedRows) {
            foreach ($duplicates as $dup) {
                foreach ($presentTables as $table) {
                    $movedRows += DB::table($table)
                        ->where('client_id', $dup->id)
                        ->update(['client_id' => $master->id]);
                }

                EventSyncMetadata::where('entity_type', 'clients')
                    ->where('entity_id', $dup->id)
                    ->update(['entity_id' => $master->id]);

                $dup->delete();
            }
        });

        $this->line("    перенесено строк: {$movedRows}, soft-deleted: {$duplicates->count()}");

        return ['merged_count' => $duplicates->count(), 'moved_rows' => $movedRows];
    }

    /**
     * Выбрать master из группы дублей.
     * Приоритет: максимум заказов → самый свежий updated_at → минимальный id.
     */
    private function pickMaster(\Illuminate\Support\Collection $clients): Client
    {
        $ids = $clients->pluck('id')->all();
        $orderCounts = DB::table('orders')
            ->selectRaw('client_id, COUNT(*) as cnt')
            ->whereIn('client_id', $ids)
            ->whereNull('deleted_at')
            ->groupBy('client_id')
            ->pluck('cnt', 'client_id');

        return $clients
            ->sort(function (Client $a, Client $b) use ($orderCounts) {
                $byOrders = ($orderCounts[$b->id] ?? 0) <=> ($orderCounts[$a->id] ?? 0);
                if ($byOrders !== 0) {
                    return $byOrders;
                }

                $byUpdated = ($b->updated_at?->getTimestamp() ?? 0) <=> ($a->updated_at?->getTimestamp() ?? 0);
                if ($byUpdated !== 0) {
                    return $byUpdated;
                }

                return $a->id <=> $b->id;
            })
            ->first();
    }
}
