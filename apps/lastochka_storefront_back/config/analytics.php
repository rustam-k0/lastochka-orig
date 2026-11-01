<?php

return [

    /*
    |--------------------------------------------------------------------------
    | B2C поведенческие события (LASTOCHKI-JAMI-11659)
    |--------------------------------------------------------------------------
    |
    | Whitelist типов событий, принимаемых POST /api/v1/analytics/events.
    | Событие вне списка отклоняется per-event (батч не валится целиком).
    | Длина типа ограничена колонкой event_outbox.event_type VARCHAR(20).
    |
    */

    'allowed_events' => [
        'app.opened',
        'product.viewed',
        'category.viewed',
        'cart.item_added',
        'cart.item_removed',
        'search.performed',
        'stock.intent_denied',
        'checkout.started',
    ],

    // Максимум событий в одном батче
    'max_batch_size' => 100,

    // Максимальный размер payload одного события (JSON, байт)
    'max_payload_bytes' => 8192,

    // occurred_at принимается не старше N дней (офлайн-буфер мобилки)
    'max_event_age_days' => 7,

    // и не дальше N минут в будущее (расхождение часов устройства)
    'max_future_skew_minutes' => 5,

];
