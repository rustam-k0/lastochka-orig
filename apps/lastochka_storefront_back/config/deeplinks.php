<?php

// Deep Links (iOS Universal Links / Android App Links).
// Значения — per-fork в .env, файлы public/.well-known/* не хранятся в git.
return [
    'ios' => [
        // Через запятую: TEAMID.bundle.id[,TEAMID.bundle.id2]
        'app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('DEEPLINK_IOS_APP_IDS', ''))))),
        // Через запятую, например: /estimates/shared/*,/catalog/product/*
        'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('DEEPLINK_IOS_PATHS', '/*'))))),
    ],
    'android' => [
        'package' => (string) env('DEEPLINK_ANDROID_PACKAGE', ''),
        // Через запятую, формат AA:BB:...:FF
        'sha256_cert_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('DEEPLINK_ANDROID_SHA256_FINGERPRINTS', ''))))),
    ],
    // Ссылки и схема для страницы-заглушки универсальных ссылок (у кого приложение не установлено)
    'apps' => [
        // Например: https://apps.apple.com/ru/app/electrohub/id1234567890
        'appstore_url' => (string) env('DEEPLINK_APPSTORE_URL', ''),
        // Например: https://play.google.com/store/apps/details?id=com.app.electrohub
        'google_play_url' => (string) env('DEEPLINK_GOOGLE_PLAY_URL', ''),
        // Кастомная URL-схема приложения без «://», например: electrohub
        'scheme' => (string) env('DEEPLINK_APP_SCHEME', ''),
    ],
];
