<?php

namespace App\Console\Commands;

use App\Models\Shift;
use Illuminate\Console\Command;

class FinishShifts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:finishShifts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Завершение смен сборщиков и курьеров';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
      Shift::finishShifts();
      return Command::SUCCESS;
    }
}
