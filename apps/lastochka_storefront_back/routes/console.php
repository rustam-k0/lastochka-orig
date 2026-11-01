<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pickup:auto-cancel')->everyMinute();
Schedule::command('orders:cancel-expired-surcharge')->everyMinute();
// Черновики заказов (модуль draft_orders_until_payment): закрыть те, где
// оплата не подтвердилась за draft_order_ttl_minutes — вернуть товар в остаток.
Schedule::command('orders:cancel-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('addresses:cleanup-orphan-photos')->monthlyOn(1, '03:00');
Schedule::command('stock:reset-fixed')->dailyAt('00:05');

// Просмотры карточек старше personal_view_ttl_days (группа «Смотрел, но не купил»).
Schedule::command('product-views:prune')->dailyAt('03:40')->withoutOverlapping();

// Страховка лояльности: перепубликовать создание карт, не подтверждённых 1С
// (потерянное loyalty_card.created / упавший consumer). Идемпотентно.
Schedule::command('loyalty:republish-pending-cards')->hourly();

// Очистка опубликованных событий outbox и осиротевших метаданных (ежедневно в 4:10).
// Без неё event_outbox растёт бесконечно: на проде таблица 1С-парсера дошла до
// 41 ГБ и выбрала весь диск, положив все бэкенды и шину RabbitMQ.
Schedule::command('rabbitmq:cleanup-outbox --days=7 --orphans')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/outbox-cleanup.log'));
