<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222;">
<h2 style="margin: 0 0 16px;">{{ $title }}</h2>

<p style="margin: 0 0 16px; line-height: 1.5;">{{ $body }}</p>

@if ($orderUrl)
    <p style="margin: 0 0 16px;">
        <a href="{{ $orderUrl }}"
           style="display: inline-block; padding: 10px 20px; background: #222; color: #fff; text-decoration: none; border-radius: 4px;">
            Перейти к заказу
        </a>
    </p>
@endif

<p style="margin: 24px 0 0; color: #888; font-size: 12px;">
    Вы получили это письмо, потому что включили email-уведомления в настройках приложения.
</p>
</body>
</html>
