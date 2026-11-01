<?php

namespace App\Console\Commands;

use App\Jobs\CheckRemains;
use Illuminate\Console\Command;

class CheckRemainsCommand extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'check:remains';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Проверяет товары на наличие остатков. Если остатки меньше выставленного ограничения, то скрыть товар с витрины';

  /**
   * Execute the console command.
   *
   * @return int
   */
  public function handle()
  {
    CheckRemains::dispatch();
    return Command::SUCCESS;
  }
}
