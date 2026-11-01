<?php

return [

    /*
    | Таймзона отчётного дня: границы stat_date и сравнение с naive
    | slot-датами docker-БД считаются в ней.
    */
    'timezone' => env('ANALYTICS_TIMEZONE', 'Europe/Moscow'),

    /*
    | В storefront у отзыва нет флага «жалоба» — жалобой считается оценка
    | не выше этого порога (#11601).
    */
    'complaint_max_rating' => (int) env('ANALYTICS_COMPLAINT_MAX_RATING', 2),

    /*
    | Кэш живой статистики текущей смены (сек) и store кэша.
    | На сервере можно переключить на redis через CACHE_STORE.
    */
    'live_cache_seconds' => (int) env('ANALYTICS_LIVE_CACHE_SECONDS', 60),

    /*
    | Цели «прогресс к бонусу» на экране сотрудника: заказов за период.
    */
    'goals' => [
        'shift' => (int) env('ANALYTICS_GOAL_SHIFT', 10),
        'week' => (int) env('ANALYTICS_GOAL_WEEK', 45),
        'month' => (int) env('ANALYTICS_GOAL_MONTH', 180),
    ],

    /*
    | Пороги бейджей (#11602). Ключи = code бейджа в /me/achievements.
    */
    'badges' => [
        'sprinter_orders_per_shift' => (int) env('ANALYTICS_BADGE_SPRINTER', 7),
        'no_errors_streak_orders' => (int) env('ANALYTICS_BADGE_NO_ERRORS', 10),
        'five_star_streak_ratings' => (int) env('ANALYTICS_BADGE_FIVE_STAR', 8),
        'fast_courier_avg_sec' => (int) env('ANALYTICS_BADGE_FAST_COURIER', 1500),
        'no_complaint_days' => (int) env('ANALYTICS_BADGE_NO_COMPLAINT_DAYS', 5),
    ],

];
