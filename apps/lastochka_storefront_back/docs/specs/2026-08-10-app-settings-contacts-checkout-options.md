# App-settings: контакты приложения и опции чекаута (MENDEV-15, MENDEV-17)

Контракт изменённого API storefront для мобилки: контакты/соцсети/ссылки на приложения в общем запросе и настраиваемые опции оформления заказа («Охладить перед доставкой»). Не задеплоено (ждёт пуша).

## Что изменилось

1. В `GET /api/v1/app-settings` появился блок `contacts` — телефоны, почты, соцсети, ссылки на мобильные приложения.
2. Там же появился блок `checkoutOptions` — доступные опции оформления заказа (чекбоксы чекаута).
3. `POST /api/v1/orders` принимает массив `checkoutOptions` — коды выбранных опций.
4. В объекте заказа (`GET /orders`, `GET /orders/{id}`) появилось поле `checkoutOptions`.
5. Попутно (MENDEV-16): поле `address` у магазина в `GET /api/v1/stores` теперь заполняется из админки (раньше всегда был `null`; может быть `null`, если адрес не заполнен).

## База

| | |
|---|---|
| Endpoint'ы | `GET /api/v1/app-settings` (публичная), `POST /api/v1/orders` (Bearer) |
| Dev | `https://store.raz-dev.mendev.ru` |
| Content-Type | `application/json` |

## Ручки

### GET /api/v1/app-settings — новые блоки

Форма ответа:

```
data: {
  launchBanner: {...} | null,        // как раньше
  additionalInfo: [...],             // как раньше
  contacts: {                        // НОВОЕ
    phones: string[],                // может быть пустым
    emails: string[],                // может быть пустым
    socialNetworks: [                // может быть пустым
      {name: string, url: string, logo: string | null}  // logo — полный URL
    ],
    appLinks: {
      appStore: string | null,
      googlePlay: string | null,
      ruStore: string | null
    }
  },
  checkoutOptions: [                 // НОВОЕ, может быть пустым
    {code: string, label: string}    // например chill_before_delivery / «Охладить перед доставкой»
  ]
}
```

Содержимое `contacts` и `checkoutOptions` управляется настройками на сервере; состав опций чекаута может меняться без изменения контракта — `code` произвольная строка, `label` — готовый текст для чекбокса.

### POST /api/v1/orders — новое поле

| Поле | Тип | Обяз. | Описание | Ограничения |
|---|---|---|---|---|
| checkoutOptions | string[] | нет | Коды выбранных опций из `app-settings → checkoutOptions` | max 20 элементов, код max 64 символа; код обязан быть среди включённых на сервере |

Остальные поля заказа не менялись.

### Заказ в ответах (`GET /orders`, `GET /orders/{id}`, ответ создания)

В объекте заказа появилось поле:

```
checkoutOptions: [{code: string, label: string}]   // [] если опции не выбирались
```

`label` — снапшот на момент оформления: переименование опции на сервере не меняет уже созданные заказы.

## Коды ответов

| Ситуация | HTTP | Тело |
|---|---|---|
| Неизвестный/выключенный код опции в `checkoutOptions` | 422 | `{"message": "...", "errors": {"checkoutOptions": ["Неизвестные опции оформления: <коды>"]}}` |

## Пример

```bash
curl 'https://store.raz-dev.mendev.ru/api/v1/app-settings'
```

```json
{"data": {"launchBanner": null, "additionalInfo": [], "contacts": {"phones": ["+7 800 100-20-30"], "emails": ["sales@razgulayka.ru"], "socialNetworks": [{"name": "ВКонтакте", "url": "https://vk.com/razgulayka", "logo": null}], "appLinks": {"appStore": null, "googlePlay": null, "ruStore": null}}, "checkoutOptions": [{"code": "chill_before_delivery", "label": "Охладить перед доставкой"}]}}
```

## Обратная совместимость

Все новые поля добавлены к существующим ответам, прежние поля не менялись и не переименовывались. `checkoutOptions` в POST /orders опционален — старый клиент создаёт заказы как раньше.
