<?php

namespace App\Providers;

use App\Models\System\EventOutbox;
use App\Observers\EventOutboxObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрируем Observer для автоматического создания метаданных
        // при импорте сущностей из RetailCRM
        EventOutbox::observe(EventOutboxObserver::class);
    }
}
