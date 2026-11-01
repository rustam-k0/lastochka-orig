<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Отклик на вакансию</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222;">
<h2 style="margin: 0 0 16px;">Отклик на вакансию</h2>

<table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr>
        <td style="color: #666;">Вакансия</td>
        <td><strong>{{ $vacancy->title }}</strong></td>
    </tr>
    @if ($vacancy->city)
        <tr>
            <td style="color: #666;">Город</td>
            <td>{{ $vacancy->city }}</td>
        </tr>
    @endif
    <tr>
        <td style="color: #666;">Имя кандидата</td>
        <td>{{ $data->name }}</td>
    </tr>
    <tr>
        <td style="color: #666;">Телефон</td>
        <td>{{ $data->phone }}</td>
    </tr>
    <tr>
        <td style="color: #666;">Электронная почта</td>
        <td>{{ $data->email }}</td>
    </tr>
    <tr>
        <td style="color: #666;">Дата и время отправки</td>
        <td>{{ $data->sentAt->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
    </tr>
    <tr>
        <td style="color: #666;">Источник отправки</td>
        <td>{{ $data->sourceLabel() }}</td>
    </tr>
</table>

@if ($data->comment)
    <h3 style="margin: 24px 0 8px;">Сопроводительное письмо</h3>
    <p style="white-space: pre-line; margin: 0;">{{ $data->comment }}</p>
@endif

<p style="margin: 24px 0 0; color: #666;">Резюме приложено к письму файлом «{{ $data->resumeName }}».</p>
</body>
</html>
