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
    // Страховочное закрытие забытых смен: сотрудник закрывает смену сам
    // (мутация ShiftFinish), но незакрытые не должны жить вечно — без
    // finished_at не считается shift_seconds в B2E-аналитике (#11599).
    // 04:00 — гарантированно после конца рабочего дня всех магазинов.
    $schedule->command('command:finishShifts')->dailyAt('04:00');
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
