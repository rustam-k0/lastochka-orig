<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Loyalty\LoyaltyCardService;
use Illuminate\Console\Command;

class BackfillLoyaltyCardsCommand extends Command
{
    protected $signature = 'loyalty:backfill-cards {--chunk=200 : Размер чанка}';

    protected $description = 'Создать карты лояльности существующим пользователям без карты (бекфил, LASTOCHKI-JAMI-12013)';

    public function handle(LoyaltyCardService $cards): int
    {
        $created = 0;

        User::query()
            ->whereNull('card_number')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($users) use ($cards, &$created) {
                foreach ($users as $user) {
                    if ($cards->ensureCard($user)) {
                        $created++;
                    }
                }
            });

        $this->info("Создано карт: {$created}");

        return self::SUCCESS;
    }
}
