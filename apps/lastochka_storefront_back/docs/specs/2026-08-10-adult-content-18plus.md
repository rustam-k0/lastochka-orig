# Товары 18+: подтверждение возраста и фильтрация каталога (MENDEV-8, MENDEV-9)

Контракт изменённого API storefront для мобилки: флаг подтверждения совершеннолетия в профиле и автоматическое скрытие 18+ товаров из выдачи. Не задеплоено (ждёт пуша).

## Что изменилось

1. `POST /api/v1/auth/phone-confirm` принимает опциональный флаг `adultConfirmed`.
2. Новая ручка `POST /api/v1/auth/adult-confirm` — изменение флага 18+ в профиле.
3. В объекте пользователя (`user` в phone-confirm, `data` в GET/PUT profile) появилось поле `adultConfirmed: bool`.
4. Все списочные ручки каталога скрывают товары с `adultOnly: true`, если 18+ не подтверждён. Для гостя появился параметр `showAdult`.

## База

| | |
|---|---|
| Dev | `https://store.raz-dev.mendev.ru` |
| Auth | каталожные ручки публичные (Bearer опционален), `adult-confirm` — только с Bearer |
| Content-Type | `application/json` |

## Ручки

### POST /api/v1/auth/phone-confirm — новое поле

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| adultConfirmed | boolean | нет | `true` — подтверждение 18+ с экрана авторизации, фиксируется в профиле. `false` или отсутствие ранее данное подтверждение НЕ сбрасывает |

Ответ не менялся: `{token, user}`; в `user` добавлено `adultConfirmed: bool`.

### POST /api/v1/auth/adult-confirm — согласие 18+ (новая, auth)

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| confirmed | boolean | да | `true` — показывать 18+ товары, `false` — скрывать |

Ответ `200`: `{"data": <User>}` с обновлённым `adultConfirmed`. В отличие от phone-confirm, эта ручка применяет и `false` (сброс флага).

### Параметр showAdult в каталожных ручках

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| showAdult | boolean | нет | Только для гостя: `true`/`1` — показать 18+ товары. Для запроса с Bearer-токеном параметр игнорируется — действует `adultConfirmed` профиля |

Параметр принимают: товары категории (`stores/{store}/categories/{category}/products` и slug-вариант), похожие (`products/{id}/similar`), сопутствующие (`products/{id}/related`), группы товаров (`product-groups`, `stores/{store}/product-groups`, `product-groups/{id}/products`, `stores/{store}/product-groups/slug/{slug}/products`), товары акции (`promotions/{id}/products`), поиск (`search/products`, `search/stores/{store}/products`, `search/suggestions`, `search/recommendations`).

## Поведение сервера

| Условие | Что отдаёт сервер |
|---|---|
| Гость без `showAdult` (или `showAdult=0`) | Списки без товаров `adultOnly: true` |
| Гость с `showAdult=1` | Полные списки, включая 18+ |
| Bearer, `adultConfirmed=false` в профиле | Списки без 18+ независимо от `showAdult` |
| Bearer, `adultConfirmed=true` в профиле | Полные списки без параметров |
| Карточка товара `GET /products/{id}`, slug-вариант, поиск по штрихкоду | Отдаются всегда, включая 18+ (в `Product` есть `adultOnly: bool` — поле существовало и раньше) |
| Избранное и сравнение неподтверждённого пользователя | 18+ товары скрыты из `data`; `total` избранного считает все записи |

## Коды ответов

| Ситуация | HTTP | Тело |
|---|---|---|
| `adult-confirm` без токена | 401 | `{"message": "Unauthenticated."}` |
| `adult-confirm` без `confirmed` / не boolean | 422 | `{"message": "...", "errors": {"confirmed": [...]}}` |

## Пример

```bash
curl -X POST 'https://store.raz-dev.mendev.ru/api/v1/auth/adult-confirm' \
  -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \
  -d '{"confirmed": true}'
```

```json
{"data": {"id": 8, "phone": "+79990001122", "adultConfirmed": true, "...": "..."}}
```

## Обратная совместимость

Ломающее изменение для гостевых экранов: без `showAdult` гость перестаёт видеть 18+ товары в списках (раньше видел все). Авторизованный пользователь со старой версией приложения видит 18+ только после подтверждения через новые поля. Форма ответов ручек не менялась, `adultConfirmed` в `User` — добавленное поле.
