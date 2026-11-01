<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Loyalty\LoyaltyCardService;
use Illuminate\Console\Command;

class RepublishPendingLoyaltyCardsCommand extends Command
{
    protected $signature = 'loyalty:republish-pending-cards {--older-than=60 : Возраст карты в минутах} {--limit=1000 : Максимум карт за один запуск}';

    protected $description = 'Перепубликовать loyalty_card.created для карт, зависших без подтверждения 1С (страховка от потери события)';

    public function handle(LoyaltyCardService $cards): int
    {
        $threshold = now()->subMinutes((int) $this->option('older-than'));
        $limit = (int) $this->option('limit');
        $republished = 0;

        $users = User::query()
            ->whereNotNull('card_number')
            ->whereNull('card_confirmed_at')
            ->where('card_created_at', '<', $threshold)
            ->orderBy('id')
            ->take($limit)
            ->get();

        foreach ($users as $user) {
            $cards->publishCreated($user);
            $republished++;
        }

        $this->info("Перепубликовано: {$republished}");

        return self::SUCCESS;
    }
}
