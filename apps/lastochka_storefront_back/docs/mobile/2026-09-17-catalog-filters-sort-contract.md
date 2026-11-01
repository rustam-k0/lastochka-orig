# Фильтры и сортировка каталога (MID-304)

Контракт изменённого REST API витрины (`mendev_storefront_back`) для клиентского приложения: экран «Фильтр» в каталоге и в поиске по магазину. Описывает, что принимает и отдаёт бэкенд.

## Что изменилось

1. `GET /stores/{store}/categories/{category}/filters` и `GET /stores/{store}/categories/slug/{slug}/filters` принимают текущий выбор пользователя: `priceMin`, `priceMax`, `filter[<code>]`, а также `onlyDiscounted`, `sugarFree`, `showAdult`.
2. У значений секций `checkbox` и у секций `flag` появились поля `count` и `available`.
3. Границы `min` / `max` секции `price` считаются с учётом выбора в остальных группах и округляются наружу до целых.
4. Новые значения `sort`: `name` и `relevance` (листинг категории и поиск в магазине).
5. `GET /search/stores/{store}/products` принимает `priceMin`, `priceMax`, `filter[<code>]`, `countOnly`.
6. Новая ручка `GET /search/stores/{store}/filters`: фильтры выдачи поиска в том же формате, что у категории.
7. Тестовые данные на dev: в магазине `907` у товаров листинга заполнены атрибуты `brand` («Бренд», 93 значения во всех категориях) и `country` («Страна-производитель», 15 значений). В магазине `1` те же атрибуты у его товаров.

## База

| | |
|---|---|
| Base URL (dev) | `https://store.raz-dev.mendev.ru/api/v1` |
| Base URL (stage) | `https://store.raz-stage.rusrazgul.ru/api/v1`: изменения MID-304 на stage пока не выкачены |
| Протокол | REST, `GET`, ответ `application/json` |
| Авторизация | Не требуется. С заголовком `Authorization: Bearer <token>` видимость товаров 18+ берётся из профиля (`adultConfirmed`), параметр `showAdult` игнорируется |
| Rate limit | `/search/*`: 100 запросов в минуту (заголовки `X-RateLimit-Limit`, `X-RateLimit-Remaining`, при превышении `429`). Каталожные ручки `/stores/*`: без лимита |

## Параметры выбора (общие для всех ручек ниже)

| Параметр | Тип | Описание |
|---|---|---|
| `priceMin` | int | Нижняя граница цены, включительно |
| `priceMax` | int | Верхняя граница цены, включительно |
| `filter[<code>]` | string | Коды значений через запятую: `filter[brand]=tess,greenfield`. `<code>` это `code` секции, значение это `values[].code`. Между разными `<code>` условие AND, внутри одного `<code>` условие OR. Массивная форма `filter[brand][]=tess&filter[brand][]=greenfield` эквивалентна. Для секции `type:"flag"`: `filter[<code>]=1` (или `true`) |
| `onlyDiscounted` | bool | Только товары со скидкой |
| `sugarFree` | bool | Только товары без сахара |
| `showAdult` | bool | Гость: учитывать товары 18+. По умолчанию скрыты |

Булевы значения: в `/stores/*` принимаются `1`, `0`, `true`, `false`. В `/search/*` параметры `onlyDiscounted` и `sugarFree` принимают только `1` / `0` (`true` даёт `422`), `countOnly` принимает `1`, `0`, `true`, `false`.

В URL квадратные скобки кодируются: `filter%5Bbrand%5D=tess,greenfield`.

## Ручки

### 1. Фильтры категории

```
GET /stores/{store}/categories/{category}/filters
GET /stores/{store}/categories/slug/{slug}/filters
```

| Параметр | Где | Тип | Обяз. | Описание |
|---|---|---|---|---|
| `store` | path | int | да | ID магазина |
| `category` | path | int | да | ID категории; `0` означает все категории магазина |
| `slug` | path | string | да | Slug категории; `-` означает все категории магазина |
| параметры выбора | query | | нет | См. «Параметры выбора» |

`200`:

```json
{ "data": [ FilterSection, ... ] }
```

### 2. Товары категории (изменился только `sort`)

```
GET /stores/{store}/categories/{category}/products
GET /stores/{store}/categories/slug/{slug}/products
```

Параметры выбора, `countOnly`, `page`, `perPage`, `cityId` работают как раньше. Значения `sort`: см. «Сортировка».

`200`: `{ "hasMore": bool, "data": Product[], "total": int }`; при `countOnly=1`: `{ "total": int }`.

### 3. Поиск товаров в магазине

```
GET /search/stores/{store}/products
```

| Параметр | Тип | Обяз. | Описание | Ограничения |
|---|---|---|---|---|
| `query` | string | да | Поисковый запрос | 2..255 символов |
| `page` | int | нет | Номер страницы | ≥ 1, по умолчанию 1 |
| `perPage` | int | нет | Товаров на странице | 1..100, по умолчанию 20 |
| `sort` | string | нет | См. «Сортировка» | по умолчанию `relevance` |
| `categoryId` | int | нет | Чип категории над выдачей | ≥ 1 |
| `brandId` | int | нет | Бренд (сущность бренда, не атрибут `brand`) | ≥ 1 |
| `priceMin`, `priceMax` | number | нет | Диапазон цены | ≥ 0 |
| `filter[<code>]` | string | нет | См. «Параметры выбора» | объект |
| `onlyDiscounted`, `sugarFree` | bool | нет | | `0` / `1` |
| `countOnly` | bool | нет | Вернуть только количество | `0`, `1`, `true`, `false` |
| `showAdult` | bool | нет | Гость: учитывать 18+ | |

`200`:

```json
{
  "hasMore": true,
  "data": [ Product, ... ],
  "total": 10,
  "categories": [ { "id": 9, "name": "Чай", "count": 10 } ]
}
```

`categories` считается с учётом `priceMin`, `priceMax`, `filter[]` и без учёта `categoryId`.

При `countOnly`: `{ "total": 10 }` (без `data`, `categories`, `hasMore`).

### 4. Фильтры поиска в магазине (новая)

```
GET /search/stores/{store}/filters
```

| Параметр | Тип | Обяз. | Описание | Ограничения |
|---|---|---|---|---|
| `query` | string | да | Тот же запрос, что в выдаче | 2..255 символов |
| `categoryId` | int | нет | Выбранный чип категории | ≥ 1 |
| `brandId` | int | нет | Бренд (сущность) | ≥ 1 |
| `priceMin`, `priceMax` | number | нет | Выбранный диапазон | ≥ 0 |
| `filter[<code>]` | string | нет | Выбранные значения | объект |
| `onlyDiscounted`, `sugarFree` | bool | нет | | `0` / `1` |
| `showAdult` | bool | нет | Гость: учитывать 18+ | |

`200`: `{ "data": [ FilterSection, ... ] }`, формат как у ручки 1.

## Типы

```
FilterSection =
  | { code: "price", name: string, type: "range", min: int, max: int }
  | { code: string, name: string, type: "checkbox", values: FilterValue[] }
  | { code: string, name: string, type: "flag", count: int, available: bool }   # count, available: новые

FilterValue = {
  id: int,
  code: string,      # значение для filter[<code>]; у части значений из 1С это id строкой
  name: string,
  count: int,        # новое
  available: bool    # новое, равно count > 0
}
```

Поле `name` не бывает `null`. У товара (`Product`) название отдаётся полем `title`; поля `name` у товара в ответе нет.

## Поведение сервера

| Условие | Что отдаёт сервер |
|---|---|
| Выбор не передан | Все значения `available: true`; `count` = число товаров выдачи с этим значением |
| Выбор передан | Набор секций и значений тот же, что без выбора. Меняются только `count` / `available` и `min` / `max` |
| `count` значения в группе X | Число товаров выдачи, подходящих под выбор во всех группах, кроме X, и имеющих это значение. Совпадает с `total` листинга или поиска с `countOnly`, если в группе X выбрано только это значение, а остальные группы как в запросе |
| `count` = 0 | `available: false`, значение остаётся в `values` |
| `min` / `max` секции `price` | По товарам, подходящим под выбор во всех группах, кроме цены (`priceMin` / `priceMax` на границы не влияют). Товары с нулевой ценой не учитываются. `min` округлён вниз, `max` вверх |
| Под выбор в других группах не подходит ни один товар | `price`: `min: 0, max: 0`; у значений `count: 0` |
| `filter[<code>]` с кодом, которого нет среди секций | Выдача пустая: у всех значений `count: 0`, `price` `0 / 0`; листинг отдаёт `total: 0` |
| Секция `flag` | Присутствует, если в выдаче без выбора есть хотя бы один товар с этим флагом |
| `onlyDiscounted`, `sugarFree`, 18+, `query`, `categoryId`, `brandId` | Сужают саму выдачу: влияют на состав секций и значений |
| Пустая выдача (или поиск недоступен) | `data` из одной секции `price` с `min: 0, max: 0` |
| Порядок секций | `price` первой, далее атрибуты в порядке карточки товара, `flag` в конце |
| Порядок значений | По `name`, натуральная сортировка; латиница раньше кириллицы |
| Цена в сетевом каталоге | Цена товара в магазине `{store}` |

### Сортировка

| `sort` | Категория (`/stores/*/products`) | Поиск в магазине (`/search/stores/*/products`) |
|---|---|---|
| не передан | по названию | по релевантности |
| `relevance` | по названию (поискового запроса нет) | по релевантности |
| `name` | по названию А→Я | по названию А→Я |
| `price_asc` | по цене ↑ | по цене ↑ |
| `price_desc` | по цене ↓ | по цене ↓ |
| `popular` | по числу заказов ↓ | по числу заказов ↓ |
| `discount` | по размеру скидки в % ↓ | по размеру скидки в % ↓ |
| `newest` | новые первыми | новые первыми |
| другое значение | по названию | по релевантности |

## Коды ответов

| Ситуация | HTTP | Тело |
|---|---|---|
| Успех | 200 | См. «Ручки» |
| Магазин не найден | 404 | `{"message": "No query results for model [App\\Models\\Store] 99999"}` |
| Категория по id или slug не найдена | 404 | `{"message": "No query results for model [App\\Models\\Category] ..."}` |
| `/search/*`: невалидные параметры (`query` отсутствует, короче 2 или длиннее 255; `perPage` > 100; `priceMin` / `priceMax` не число или < 0; `filter` не объект; `countOnly` не из `0`/`1`/`true`/`false`; `onlyDiscounted` / `sugarFree` не `0`/`1`) | 422 | `{"message": "...", "errors": {"<параметр>": ["..."]}}` |
| `/search/*`: превышен лимит 100 в минуту | 429 | `{"message": "Too Many Attempts."}` |
| `/stores/*`: нечисловой `priceMin` / `priceMax` | 200 | Значение приводится к `0` |

## Примеры

Фильтры категории «Чай» (id 9) магазина 907, выбран бренд GREENFIELD и цена от 100:

```bash
curl -s 'https://store.raz-dev.mendev.ru/api/v1/stores/907/categories/9/filters?filter%5Bbrand%5D=greenfield&priceMin=100'
```

```json
{
  "data": [
    { "code": "price", "name": "Цена", "type": "range", "min": 89, "max": 430 },
    {
      "code": "brand", "name": "Бренд", "type": "checkbox",
      "values": [
        { "id": 4, "code": "ahmad_tea", "name": "AHMAD TEA", "count": 6, "available": true },
        { "id": 5, "code": "akbar", "name": "AKBAR", "count": 1, "available": true },
        { "id": 6, "code": "alpen_gold", "name": "ALPEN GOLD", "count": 0, "available": false }
      ]
    },
    {
      "code": "country", "name": "Страна-производитель", "type": "checkbox",
      "values": [
        { "id": 105, "code": "azerbaydzhan", "name": "Азербайджан", "count": 0, "available": false },
        { "id": 107, "code": "velikobritaniya", "name": "Великобритания", "count": 0, "available": false }
      ]
    }
  ]
}
```

(списки `values` сокращены)

Количество для кнопки в поиске:

```bash
curl -s 'https://store.raz-dev.mendev.ru/api/v1/search/stores/907/products?query=чай&filter%5Bbrand%5D=greenfield&priceMin=100&countOnly=1'
```

```json
{ "total": 9 }
```

Выдача поиска с фильтром и сортировкой:

```bash
curl -s 'https://store.raz-dev.mendev.ru/api/v1/search/stores/907/products?query=чай&filter%5Bbrand%5D=greenfield&sort=price_asc&perPage=2'
```

```json
{
  "hasMore": true,
  "data": [
    { "id": 711, "title": "Чай Гринфилд Голден черный 25х 2г", "price": 89.99, "...": "..." },
    { "id": 727, "title": "Чай Гринфилд Флаинг Драгон зеленый 25х 2г", "price": 119.99, "...": "..." }
  ],
  "total": 10,
  "categories": [ { "id": 9, "name": "Чай", "count": 10 } ]
}
```

Фильтры выдачи поиска:

```bash
curl -s 'https://store.raz-dev.mendev.ru/api/v1/search/stores/907/filters?query=чай&filter%5Bcountry%5D=velikobritaniya'
```

Секция `brand` в ответе: доступно только значение `ahmad_tea` (`count: 8`), `price`: `min: 72, max: 305`.

## Обратная совместимость

- Поля `count` и `available` добавлены к существующим; прежние поля, типы и порядок секций не менялись.
- Без параметров выбора `/filters` отдаёт те же секции и значения, что раньше, с двумя отличиями: у гостя без `showAdult` товары 18+ не учитываются (как в листинге), а `min` / `max` округляются наружу вместо отбрасывания дробной части.
- Прежние значения `sort` работают как раньше.
- Новые параметры поиска необязательны; без них ответ `/search/stores/{store}/products` прежний.
- Ручка `/search/stores/{store}/filters` новая.
