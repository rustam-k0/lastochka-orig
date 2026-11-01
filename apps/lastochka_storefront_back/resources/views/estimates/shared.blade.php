<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if($appstoreId)
        <meta name="apple-itunes-app" content="app-id={{ $appstoreId }}, app-argument={{ url()->current() }}">
    @endif
    <title>{{ $estimate ? 'Смета «'.$estimate->name.'»' : 'Смета не найдена' }} · {{ config('app.name') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 15px; line-height: 1.45; color: #1a1a1a; background: #f4f5f7;
        }
        .page { max-width: 560px; margin: 0 auto; padding: 16px 16px 40px; }
        .brand { text-align: center; font-weight: 700; font-size: 17px; padding: 12px 0 20px; color: #333; }
        .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .desc { color: #666; margin-bottom: 8px; }
        .items { margin-top: 16px; border-top: 1px solid #eee; }
        .item { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eee; align-items: center; }
        .thumb {
            width: 48px; height: 48px; border-radius: 8px; object-fit: cover;
            background: #f0f0f0; flex-shrink: 0;
        }
        .thumb.placeholder { display: flex; align-items: center; justify-content: center; color: #bbb; font-size: 20px; }
        .item-info { flex: 1; min-width: 0; }
        .item-title { font-weight: 500; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .item-meta { color: #888; font-size: 13px; margin-top: 2px; }
        .item-sum { font-weight: 600; white-space: nowrap; }
        .total-row { display: flex; justify-content: space-between; padding-top: 14px; font-size: 17px; font-weight: 700; }
        .cta { margin-top: 20px; text-align: center; }
        .btn {
            display: block; width: 100%; padding: 14px; border-radius: 12px; text-align: center;
            text-decoration: none; font-size: 16px; font-weight: 600;
            background: #1a1a1a; color: #fff;
        }
        .stores { display: flex; gap: 10px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .store-link {
            display: inline-block; padding: 10px 18px; border-radius: 10px; text-decoration: none;
            font-size: 14px; font-weight: 500; color: #1a1a1a; background: #fff; border: 1px solid #ddd;
        }
        .hint { color: #888; font-size: 13px; margin-top: 14px; }
        .empty { text-align: center; padding: 28px 0 8px; }
        .empty-icon { font-size: 40px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="page">
    <div class="brand">{{ config('app.name') }}</div>

    <div class="card">
        @if($estimate)
            <h1>{{ $estimate->name }}</h1>
            @if($estimate->description)
                <div class="desc">{{ $estimate->description }}</div>
            @endif

            <div class="items">
                @forelse($items as $item)
                    <div class="item">
                        @if($item['image'])
                            <img class="thumb" src="{{ $item['image'] }}" alt="" loading="lazy">
                        @else
                            <div class="thumb placeholder">🛒</div>
                        @endif
                        <div class="item-info">
                            <div class="item-title">{{ $item['title'] }}</div>
                            <div class="item-meta">
                                @if($item['sku'])
                                    Арт. {{ $item['sku'] }} ·
                                @endif
                                {{ $item['quantity'] }} × {{ number_format($item['price'], 0, ',', ' ') }} ₽
                            </div>
                        </div>
                        <div class="item-sum">{{ number_format($item['sum'], 0, ',', ' ') }} ₽</div>
                    </div>
                @empty
                    <div class="hint" style="padding-top: 12px;">В смете пока нет товаров.</div>
                @endforelse

                @if($items->isNotEmpty())
                    <div class="total-row">
                        <span>Итого</span>
                        <span>{{ number_format($total, 0, ',', ' ') }} ₽</span>
                    </div>
                @endif
            </div>
        @else
            <div class="empty">
                <div class="empty-icon">🔍</div>
                <h1>Смета не найдена</h1>
                <div class="desc">Возможно, ссылка устарела или смета была удалена.</div>
            </div>
        @endif

        <div class="cta">
            @if($estimate && $appUrl)
                <a class="btn" href="{{ $appUrl }}">Открыть в приложении</a>
            @endif

            @if($appstoreUrl || $googlePlayUrl)
                <div class="stores">
                    @if($appstoreUrl)
                        <a class="store-link" href="{{ $appstoreUrl }}">App Store</a>
                    @endif
                    @if($googlePlayUrl)
                        <a class="store-link" href="{{ $googlePlayUrl }}">Google Play</a>
                    @endif
                </div>
                <div class="hint">Установите приложение {{ config('app.name') }}, чтобы работать со сметой: добавить товары в корзину или создать свою копию.</div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
