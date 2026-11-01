@php
    $appName = (string) config('mobile_app.name');
    $appIdentifier = (string) config('mobile_app.identifier');
    $appDeveloper = (string) config('mobile_app.developer');
    $hasAppMeta = $appName !== '' || $appIdentifier !== '' || $appDeveloper !== '';

    $logoUrl = (string) config('mobile_app.logo_url');
    if ($logoUrl === '') {
        $logoUrl = asset('images/app-logo.png');
    }

    $accent = (string) config('mobile_app.accent_color');
    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#E5241F';
    }
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Удаление аккаунта@if ($appName !== '') в приложении «{{ $appName }}»@endif</title>
    <link rel="icon" type="image/png" href="{{ $logoUrl }}">
    <style>
        :root {
            --accent: {{ $accent }};
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--accent);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .card {
            background: #fff;
            border-radius: 28px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.25);
            max-width: 480px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }

        .logo {
            width: 96px;
            height: 96px;
            border-radius: 24px;
            box-shadow: 0 8px 24px rgba(229, 36, 31, 0.35);
            box-shadow: 0 8px 24px color-mix(in srgb, var(--accent) 35%, transparent);
            margin-bottom: 24px;
        }

        h1 {
            font-size: 26px;
            color: #1c1c1c;
            margin-bottom: 8px;
        }

        .subtitle {
            font-size: 15px;
            color: #8a8a8a;
            margin-bottom: 24px;
        }

        .app-meta {
            background: #fafafa;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 28px;
            text-align: left;
        }

        .app-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            font-size: 14px;
            line-height: 1.5;
            padding: 6px 0;
        }

        .app-meta-row + .app-meta-row {
            border-top: 1px solid #efefef;
        }

        .app-meta-label {
            color: #8a8a8a;
            flex-shrink: 0;
        }

        .app-meta-value {
            color: #1c1c1c;
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }

        .app-meta-value.mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-weight: 500;
            font-size: 13px;
        }

        .steps {
            list-style: none;
            text-align: left;
        }

        .steps li {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 14px 0;
        }

        .steps li + li {
            border-top: 1px solid #f2f2f2;
        }

        .step-num {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .step-text {
            font-size: 16px;
            line-height: 1.5;
            color: #1c1c1c;
            padding-top: 4px;
        }

        .step-text b {
            color: var(--accent);
        }

        .note {
            margin-top: 28px;
            background: #fdeceb;
            background: color-mix(in srgb, var(--accent) 10%, #fff);
            border-left: 4px solid var(--accent);
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 14px;
            line-height: 1.5;
            color: #7a1512;
            color: color-mix(in srgb, var(--accent) 60%, #000);
            text-align: left;
        }

        @media (max-width: 400px) {
            .card {
                padding: 32px 20px;
            }

            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="{{ $logoUrl }}" alt="Логотип приложения@if ($appName !== '') «{{ $appName }}»@endif">
        <h1>Удаление аккаунта</h1>
        <p class="subtitle">Инструкция по удалению аккаунта в мобильном приложении@if ($appName !== '') «{{ $appName }}»@endif</p>

        @if ($hasAppMeta)
            <div class="app-meta">
                @if ($appName !== '')
                    <div class="app-meta-row">
                        <span class="app-meta-label">Приложение</span>
                        <span class="app-meta-value">{{ $appName }}</span>
                    </div>
                @endif
                @if ($appIdentifier !== '')
                    <div class="app-meta-row">
                        <span class="app-meta-label">Идентификатор</span>
                        <span class="app-meta-value mono">{{ $appIdentifier }}</span>
                    </div>
                @endif
                @if ($appDeveloper !== '')
                    <div class="app-meta-row">
                        <span class="app-meta-label">Разработчик</span>
                        <span class="app-meta-value">{{ $appDeveloper }}</span>
                    </div>
                @endif
            </div>
        @endif

        <ol class="steps">
            <li>
                <span class="step-num">1</span>
                <span class="step-text">Перейдите в таб <b>«Профиль»</b>.</span>
            </li>
            <li>
                <span class="step-num">2</span>
                <span class="step-text">Нажмите на <b>номер телефона</b>.</span>
            </li>
            <li>
                <span class="step-num">3</span>
                <span class="step-text">Сверху справа нажмите на <b>иконку мусорки</b>.</span>
            </li>
            <li>
                <span class="step-num">4</span>
                <span class="step-text">Подтвердите действие. Аккаунт удалён.</span>
            </li>
        </ol>

        <div class="note">
            Удаление аккаунта необратимо: данные профиля будут удалены без возможности восстановления.
        </div>
    </main>
</body>
</html>
