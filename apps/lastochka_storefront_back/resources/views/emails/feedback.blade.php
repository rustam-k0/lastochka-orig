<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Обращение с формы обратной связи</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222;">
<h2 style="margin: 0 0 16px;">Обращение с формы обратной связи</h2>

<table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr>
        <td style="color: #666;">Имя</td>
        <td><strong>{{ $data->name }}</strong></td>
    </tr>
    @if ($data->phone)
        <tr>
            <td style="color: #666;">Телефон</td>
            <td>{{ $data->phone }}</td>
        </tr>
    @endif
    @if ($data->email)
        <tr>
            <td style="color: #666;">Электронная почта</td>
            <td>{{ $data->email }}</td>
        </tr>
    @endif
    <tr>
        <td style="color: #666;">Дата и время отправки</td>
        <td>{{ $data->sentAt->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
    </tr>
</table>

<h3 style="margin: 24px 0 8px;">Сообщение</h3>
<p style="white-space: pre-line; margin: 0;">{{ $data->message }}</p>
</body>
</html>
