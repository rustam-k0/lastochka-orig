<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 1C Exchange Scheduler
|--------------------------------------------------------------------------
|
| Расписание синхронизации с 1С согласно ТЗ:
| - Очистка старых файлов: ежедневно
|
*/


// Очистка старых файлов обмена (ежедневно в 3:00)
Schedule::command('1c:cleanup --days=7 --logs')
    ->dailyAt('03:00')
    ->appendOutputTo(storage_path('logs/1c-scheduler.log'));

// Очистка опубликованных событий outbox и осиротевших метаданных (ежедневно в 4:00).
// Без неё event_outbox растёт бесконечно: на проде таблица дошла до 41 ГБ и
// выбрала весь диск, положив все бэкенды и шину RabbitMQ.
Schedule::command('rabbitmq:cleanup-outbox --days=7 --orphans')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/outbox-cleanup.log'));
