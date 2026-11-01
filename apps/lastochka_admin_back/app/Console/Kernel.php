<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
  /**
   * Define the application's command schedule.
   *
   * @param Schedule $schedule
   * @return void
   */
  protected function schedule(Schedule $schedule)
  {
    // Очистка опубликованных событий outbox и осиротевших метаданных (ежедневно в 4:20).
    // Без неё event_outbox растёт бесконечно: на проде таблица 1С-парсера дошла
    // до 41 ГБ и выбрала весь диск, положив все бэкенды и шину RabbitMQ.
    $schedule->command('rabbitmq:cleanup-outbox --days=7 --orphans')
      ->dailyAt('04:20')
      ->withoutOverlapping()
      ->appendOutputTo(storage_path('logs/outbox-cleanup.log'));
  }

  /**
   * Register the commands for the application.
   *
   * @return void
   */
  protected function commands()
  {
    $this->load(__DIR__ . '/Commands');

    require base_path('routes/console.php');
  }
}
