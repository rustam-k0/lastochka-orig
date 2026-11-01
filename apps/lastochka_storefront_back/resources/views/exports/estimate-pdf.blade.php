<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .desc { color: #666; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .num { text-align: center; width: 30px; }
        .qty, .price, .sum { text-align: right; }
        .total { font-weight: bold; text-align: right; }
        .footer { margin-top: 20px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <h1>{{ $estimate->name }}</h1>
    @if($estimate->description)
        <div class="desc">{{ $estimate->description }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th class="num">№</th>
                <th>Артикул</th>
                <th>Название</th>
                <th class="qty">Кол-во</th>
                <th class="price">Цена</th>
                <th class="sum">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td class="num">{{ $item['num'] }}</td>
                <td>{{ $item['sku'] }}</td>
                <td>{{ $item['title'] }}</td>
                <td class="qty">{{ $item['quantity'] }}</td>
                <td class="price">{{ number_format($item['price'], 0, ',', ' ') }} ₽</td>
                <td class="sum">{{ number_format($item['sum'], 0, ',', ' ') }} ₽</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="total">Итого:</td>
                <td class="total">{{ number_format($total, 0, ',', ' ') }} ₽</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        Дата формирования: {{ now()->format('d.m.Y H:i') }}
    </div>
</body>
</html>
