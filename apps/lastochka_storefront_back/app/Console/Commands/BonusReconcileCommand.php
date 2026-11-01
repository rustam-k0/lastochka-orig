<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BonusHold;
use App\Models\User;
use Illuminate\Console\Command;

final class BonusReconcileCommand extends Command
{
    protected $signature = 'bonus:reconcile {--fix : Correct bonus_reserved drift}';
    protected $description = 'Сверка bonus_reserved с активными holds';

    public function handle(): int
    {
        $drift = 0;
        User::query()->where(function ($q) {
            $q->whereExists(function ($sub) {
                $sub->from('bonus_holds')->whereColumn('bonus_holds.user_id', 'users.id');
            })->orWhere('bonus_reserved', '>', 0);
        })->chunkById(500, function ($users) use (&$drift) {
            foreach ($users as $user) {
                $expected = (int) BonusHold::where('user_id', $user->id)
                    ->where('status', BonusHold::STATUS_HELD)->sum('amount');
                if ((int) $user->bonus_reserved !== $expected) {
                    $drift++;
                    $this->warn("user #{$user->id}: reserved={$user->bonus_reserved}, expected={$expected}");
                    if ($this->option('fix')) {
                        $user->forceFill(['bonus_reserved' => $expected])->saveQuietly();
                    }
                }
            }
        });

        $this->info($drift === 0 ? 'Расхождений нет.' : "Найдено расхождений: {$drift}" . ($this->option('fix') ? ' (исправлено)' : ''));

        return self::SUCCESS;
    }
}
