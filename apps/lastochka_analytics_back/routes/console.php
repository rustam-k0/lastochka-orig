<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('analytics:ensure-partitions')->dailyAt('03:00');

// Дневные агрегаты B2E (#11596): сегодня+вчера — часто (живой экран
// сотрудника), ночью — захват событий, доехавших с опозданием.
Schedule::command('analytics:aggregate-daily --days=2')->everyFifteenMinutes();
Schedule::command('analytics:aggregate-daily --days=4')->dailyAt('04:30');
