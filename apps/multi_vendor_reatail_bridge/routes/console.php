<?php

use App\Jobs\ProcessRetailPoll;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Products Poll Job
if (env('POLLING_PRODUCTS_ENABLED', true)) {
    Schedule::job(new ProcessRetailPoll('products'))
        ->cron('*/' . config('retailcrm.polling.products_interval', 5) . ' * * * *')
        ->name('poll_products')
        ->withoutOverlapping();
}

// Categories Poll Job
Schedule::job(new ProcessRetailPoll('categories'))
    ->cron('*/' . config('retailcrm.polling.categories_interval', 30) . ' * * * *')
    ->name('poll_categories')
    ->withoutOverlapping();

// Shops Poll Job
$shopsInterval = config('retailcrm.polling.shops_interval', 60);
if ($shopsInterval >= 60) {
    // Every X hours
    $cron = '0 */' . (int)($shopsInterval / 60) . ' * * *';
} else {
    // Every X minutes
    $cron = '*/' . $shopsInterval . ' * * * *';
}

Schedule::job(new ProcessRetailPoll('shops'))
    ->cron($cron)
    ->name('poll_shops')
    ->withoutOverlapping();

// Collectors Poll Job
//$collectorsInterval = config('retailcrm.polling.collectors_interval', 60);
//if ($collectorsInterval >= 60) {
//    // Every X hours
//    $cron = '0 */' . (int)($collectorsInterval / 60) . ' * * *';
//} else {
//    // Every X minutes
//    $cron = '*/' . $collectorsInterval . ' * * * *';
//}
//Schedule::job(new ProcessRetailPoll('collectors'))
//    ->cron($cron)
//    ->name('poll_collectors')
//    ->withoutOverlapping();
//
//// Couriers Poll Job
//$couriersInterval = config('retailcrm.polling.couriers_interval', 60);
//if ($couriersInterval >= 60) {
//    // Every X hours
//    $cron = '0 */' . (int)($couriersInterval / 60) . ' * * *';
//} else {
//    // Every X minutes
//    $cron = '*/' . $couriersInterval . ' * * * *';
//}
//
//Schedule::job(new ProcessRetailPoll('couriers'))
//    ->cron($cron)
//    ->name('poll_couriers')
//    ->withoutOverlapping();
