<?php

// Данные мобильного приложения для публичных страниц (например, инструкция по удалению аккаунта).
// Значения — per-fork в .env.
return [
    // Название приложения в сторах, например: Ласточка Джами
    'name' => trim((string) env('MOBILE_APP_NAME', '')),
    // Идентификатор пакета, например: com.app.lastochka.djami
    // По умолчанию берётся из настроек диплинков, чтобы не дублировать значение в .env.
    'identifier' => trim((string) env('MOBILE_APP_IDENTIFIER', env('DEEPLINK_ANDROID_PACKAGE', ''))),
    // Разработчик (владелец аккаунта разработчика), например: Comrades Devs LLC
    'developer' => trim((string) env('MOBILE_APP_DEVELOPER', '')),
    // URL логотипа (абсолютный или относительный к домену), например: https://api.example.com/storage/app/app-logo.png
    // Пусто — используется public/images/app-logo.png из репозитория.
    'logo_url' => trim((string) env('MOBILE_APP_LOGO_URL', '')),
    // Акцентный цвет публичных страниц в формате #RRGGBB, например: #FC874E
    // Пусто или невалидно — используется цвет по умолчанию из шаблона.
    'accent_color' => trim((string) env('MOBILE_APP_ACCENT_COLOR', '')),
];
