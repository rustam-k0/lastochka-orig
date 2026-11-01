# Cправочник методов v5

## API

### GET /api/api-versions

Получение списка доступных версий API

Метод позволяет получить все версии API, с которыми можно работать на данном аккаунте

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `versions` | `array` | Доступные версии API |

### GET /api/credentials

Получение списка доступных методов и магазинов для данного ключа

Метод позволяет получить список методов API и информацию о доступе к магазинам для данного API-ключа.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `credentials[]` | `array of strings` | `deprecated` Доступные методы для ключа |
| `scopes[]` | `array of strings` | Разрешенные доступы для ключа |
| `siteAccess` | `string` | Режим доступа к магазинам. Возможные значения:   `access_full` - доступ к данным всех магазинов;   `access_selective` - доступ к данным отдельных магазинов.   Доступные магазины перечислены в поле `sitesAvailable` |
| `sitesAvailable[]` | `array of strings` | Доступные магазины |

## Расходы

### GET /api/v5/costs

Получение списка расходов, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `cost_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CostFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | ID расходов |
| `filter[costItems][]` | `array of strings` |     | Массив символьных кодов статей расходов |
| `filter[sites][]` | `array of strings` |     | Массив символьных кодов магазинов, связанных с расходами |
| `filter[createdBy][]` | `array of integers` |     | Массив ID пользователей, создавших расход |
| `filter[orderNumber]` | `string` | `{length: {max: 255}}` | Номер заказа связанного с расходом |
| `filter[costGroups][]` | `array of strings` |     | Массив символьных кодов групп расходов |
| `filter[users][]` | `array of integers` |     | Массив ID пользователей, связанных с расходами |
| `filter[comment]` | `string` | `{length: {max: 1000}}` | Комментарий |
| `filter[orderIds][]` | `array of integers` |     | Массив внутренних ID заказов |
| `filter[orderExternalIds][]` | `array of strings` |     | Массив внешних ID заказов |
| `filter[createdAtFrom]` | `DateTime` | `Y-m-d` | Дата создания расхода (от) |
| `filter[createdAtTo]` | `DateTime` | `Y-m-d` | Дата создания расхода (до) |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Период расхода (от) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Период расхода (до) |
| `filter[minSumm]` | `integer` |     | Минимальная сумма расхода |
| `filter[maxSumm]` | `integer` |     | Максимальная сумма расхода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `costs[]` | `array of objects (Cost)` | Расход |
| `costs[][currency]` | `string` | Валюта |
| `costs[][source]` | `object (SerializedSource)` | Данные по источнику клиента |
| `costs[][source][source]` | `string` | Источник |
| `costs[][source][medium]` | `string` | Канал |
| `costs[][source][campaign]` | `string` | Кампания |
| `costs[][source][keyword]` | `string` | Ключевое слово |
| `costs[][source][content]` | `string` | Содержание кампании |
| `costs[][id]` | `integer` | ID расхода |
| `costs[][dateFrom]` | `DateTime` | Дата (от) |
| `costs[][dateTo]` | `DateTime` | Дата (до) |
| `costs[][summ]` | `float` | Сумма (в базовой валюте) |
| `costs[][costItem]` | `string` | Код статьи расхода |
| `costs[][comment]` | `string` | Комментарий |
| `costs[][createdAt]` | `DateTime` | Дата создания |
| `costs[][createdBy]` | `string` | ID пользователя, создавшего расход |
| `costs[][order]` | `object (Order)` | Заказ |
| `costs[][order][id]` | `integer` | ID заказа |
| `costs[][order][number]` | `string` | Номер заказа |
| `costs[][order][externalId]` | `string` | Внешний ID заказа |
| `costs[][userId]` | `integer` | ID пользователя, связанного с расходом |
| `costs[][sites][]` | `array of strings` | Символьные коды магазинов, по которым понесён расход |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/costs/create

Создание расхода

Для доступа к методу необходимо разрешение `cost_write`.

При создании расходов для привлечения клиентов в полях `cost[source][...]` можно указать значения соответствующих меток. Значения меток по умолчанию будут взяты из статьи расхода, символьный код которой указан в поле `cost[costItem]`.

Для указания магазинов, к которым будет прикреплён расход, необходимо указать массив их символьных кодов в поле `cost[sites][]`. По умолчанию расход будет создан для всех магазинов, доступ к которым разрешён по API-ключу.

Расход можно привязать к существующему заказу, только если статья расхода имеет признак "Относится к расходам по заказам". Для этого необходимо установить значение одного из следующих полей:

- `cost[order][id]` – внутренний ID заказа;
- `cost[order][externalId]` – внешний ID заказа;
- `cost[order][number]` – номер заказа.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск заказа по `externalId`/`number` будет осуществляться в рамках магазина указанного в параметре `site`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина. Указывается в случае привязки к заказу по externalId или number |
| `cost` | `object (SerializedCost)` |     | Данные по расходу |
| `cost[dateFrom]` | `DateTime` |     | Дата (от) |
| `cost[dateTo]` | `DateTime` |     | Дата (до) |
| `cost[summ]` | `float` |     | Сумма (в базовой валюте) |
| `cost[comment]` | `string` |     | Комментарий |
| `cost[costItem]` | `string` |     | Код статьи расхода |
| `cost[order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `cost[order][id]` | `integer` |     | Внутренний ID заказа |
| `cost[order][externalId]` | `string` |     | Внешний ID заказа |
| `cost[order][number]` | `string` |     | Номер заказа |
| `cost[userId]` | `integer` |     | ID пользователя, связанного с расходом |
| `cost[sites]` | `array` |     | Символьные коды магазинов, по которым понесён расход |
| `cost[source]` | `object (SerializedSource)` |     | Данные по источнику клиента |
| `cost[source][source]` | `string` |     | Источник |
| `cost[source][medium]` | `string` |     | Канал |
| `cost[source][campaign]` | `string` |     | Кампания |
| `cost[source][keyword]` | `string` |     | Ключевое слово |
| `cost[source][content]` | `string` |     | Содержание кампании |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного расхода |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый расход успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании расхода</p></li></ul></td></tr></tbody></table>

### POST /api/v5/costs/delete

Пакетное удаление расходов

Для доступа к методу необходимо разрешение `cost_write`.

Метод позволяет удалять пакетно до 50 расходов.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `ids[]` | `array of integers` |     | Идентификаторы удаляемых расходов |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `count` | `integer` | Количество удаленных расходов |
| `notRemovedIds[]` | `array of integers` | Идентификаторы неудаленных расходов |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Расходы успешно удалены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при пакетном удалении расходов</p></li></ul></td></tr></tbody></table>

### POST /api/v5/costs/upload

Пакетная загрузка расходов

Для доступа к методу необходимо разрешение `cost_write`.

Метод позволяет загружать пакетно до 50 расходов.

Подробную информацию по формату данных см. в описании метода `/api/v*/costs/create`.

При пакетной загрузке расходов для привлечения клиентов в полях `cost[][source][...]` можно указать значения соответствующих меток. Значения меток по умолчанию будут взяты из статьи расхода, символьный код которой указан в поле `costs[][costItem]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `costs[]` | `array of objects (SerializedCost)` |     | Данные по расходу |
| `costs[][dateFrom]` | `DateTime` |     | Дата (от) |
| `costs[][dateTo]` | `DateTime` |     | Дата (до) |
| `costs[][summ]` | `float` |     | Сумма (в базовой валюте) |
| `costs[][comment]` | `string` |     | Комментарий |
| `costs[][costItem]` | `string` |     | Код статьи расхода |
| `costs[][order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `costs[][order][id]` | `integer` |     | Внутренний ID заказа |
| `costs[][order][externalId]` | `string` |     | Внешний ID заказа |
| `costs[][order][number]` | `string` |     | Номер заказа |
| `costs[][userId]` | `integer` |     | ID пользователя, связанного с расходом |
| `costs[][sites]` | `array` |     | Символьные коды магазинов, по которым понесён расход |
| `costs[][source]` | `object (SerializedSource)` |     | Данные по источнику клиента |
| `costs[][source][source]` | `string` |     | Источник |
| `costs[][source][medium]` | `string` |     | Канал |
| `costs[][source][campaign]` | `string` |     | Кампания |
| `costs[][source][keyword]` | `string` |     | Ключевое слово |
| `costs[][source][content]` | `string` |     | Содержание кампании |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `uploadedCosts[]` | `array of integers` | Идентификаторы загруженных расходов |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Все расходы успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Не передан массив расходов, либо массив слишком большой</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть расходов не загружена</p></li></ul></td></tr></tbody></table>

### GET /api/v5/costs/{id}

Получение информации о расходе

Для доступа к методу необходимо разрешение `cost_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `string` |     | ID расхода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `cost` | `object (Cost)` | Расход |
| `cost[currency]` | `string` | Валюта |
| `cost[source]` | `object (SerializedSource)` | Данные по источнику клиента |
| `cost[source][source]` | `string` | Источник |
| `cost[source][medium]` | `string` | Канал |
| `cost[source][campaign]` | `string` | Кампания |
| `cost[source][keyword]` | `string` | Ключевое слово |
| `cost[source][content]` | `string` | Содержание кампании |
| `cost[id]` | `integer` | ID расхода |
| `cost[dateFrom]` | `DateTime` | Дата (от) |
| `cost[dateTo]` | `DateTime` | Дата (до) |
| `cost[summ]` | `float` | Сумма (в базовой валюте) |
| `cost[costItem]` | `string` | Код статьи расхода |
| `cost[comment]` | `string` | Комментарий |
| `cost[createdAt]` | `DateTime` | Дата создания |
| `cost[createdBy]` | `string` | ID пользователя, создавшего расход |
| `cost[order]` | `object (Order)` | Заказ |
| `cost[order][id]` | `integer` | ID заказа |
| `cost[order][number]` | `string` | Номер заказа |
| `cost[order][externalId]` | `string` | Внешний ID заказа |
| `cost[userId]` | `integer` | ID пользователя, связанного с расходом |
| `cost[sites][]` | `array of strings` | Символьные коды магазинов, по которым понесён расход |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Расход не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/costs/{id}/delete

Удаление расхода

Для доступа к методу необходимо разрешение `cost_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `string` |     | ID расхода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Расход успешно удалён</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при удалении расхода</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Расход не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/costs/{id}/edit

Редактирование расхода

Для доступа к методу необходимо разрешение `cost_write`.

При редактировании расходов для привлечения клиентов в полях `cost[source][...]` можно указать значения соответствующих меток.

Расход можно привязать к существующему заказу, только если статья расхода имеет признак "Относится к расходам по заказам". Для этого необходимо установить значение одного из следующих полей:

- `cost[order][id]` – внутренний ID заказа;
- `cost[order][externalId]` – внешний ID заказа;
- `cost[order][number]` – номер заказа.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск заказа по `externalId`/`number` будет осуществляться в рамках магазина указанного в параметре `site`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина. Указывается в случае привязки к заказу по externalId или number |
| `cost` | `object (SerializedCost)` |     | Данные по расходу |
| `cost[dateFrom]` | `DateTime` |     | Дата (от) |
| `cost[dateTo]` | `DateTime` |     | Дата (до) |
| `cost[summ]` | `float` |     | Сумма (в базовой валюте) |
| `cost[comment]` | `string` |     | Комментарий |
| `cost[costItem]` | `string` |     | Код статьи расхода |
| `cost[order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `cost[order][id]` | `integer` |     | Внутренний ID заказа |
| `cost[order][externalId]` | `string` |     | Внешний ID заказа |
| `cost[order][number]` | `string` |     | Номер заказа |
| `cost[userId]` | `integer` |     | ID пользователя, связанного с расходом |
| `cost[sites]` | `array` |     | Символьные коды магазинов, по которым понесён расход |
| `cost[source]` | `object (SerializedSource)` |     | Данные по источнику клиента |
| `cost[source][source]` | `string` |     | Источник |
| `cost[source][medium]` | `string` |     | Канал |
| `cost[source][campaign]` | `string` |     | Кампания |
| `cost[source][keyword]` | `string` |     | Ключевое слово |
| `cost[source][content]` | `string` |     | Содержание кампании |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID расхода |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные расхода успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении расхода</p></li></ul></td></tr></tbody></table>

## Пользовательские поля

### GET /api/v5/custom-fields

Получение списка пользовательских полей, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `custom_fields_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `choice` | `{not blank}[20\|50\|100\|250]}` |     |
| `page` | `string` | `{not blank}{range: {>=1}}}` |     |
| `filter` | `object (CustomFieldFilter)` |     |     |
| `filter[name]` | `string` |     | Название |
| `filter[code]` | `string` |     | Символьный код |
| `filter[type]` | `string` | `{choice of [boolean\|date\|datetime\|dictionary\|email\|integer\|multiselect_dictionary\|numeric\|string\|text]}` | Тип поля |
| `filter[viewMode]` | `string` | `{choice of [editable\|miss\|not_editable]}` | Вид поля в форме |
| `filter[viewModeMobile]` | `string` | `{choice of [editable\|miss\|not_editable]}` | Вид поля в мобильном приложении |
| `filter[displayArea]` | `string` | `{choice of [address\|customer\|delivery\|dimensions\|empty\|legal_details\|main_data\|payment\|shipment]}` | Область отображения |
| `filter[entity]` | `string` | `[customer\|loyalty_account\|order]` | Поля для таблицы |
| `filter[inFilter]` | `choice` | `[0\|1]` | Доступно в фильтре |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `customFields[]` | `array of objects (CustomFieldApiDocModel)` |     |
| `customFields[][name]` | `string` | Название |
| `customFields[][code]` | `string` | Символьный код |
| `customFields[][required]` | `boolean` | Обязательное |
| `customFields[][inFilter]` | `boolean` | Доступно в фильтре |
| `customFields[][inList]` | `boolean` | Доступно в списке |
| `customFields[][inGroupActions]` | `boolean` | Доступно в групповых операциях |
| `customFields[][type]` | `string` | Тип поля |
| `customFields[][entity]` | `string` | Поля для таблицы |
| `customFields[][default]` | `string` | `deprecated` Значение по умолчанию |
| `customFields[][ordering]` | `integer` | Сортировка |
| `customFields[][displayArea]` | `string` | Область отображения |
| `customFields[][viewMode]` | `string` | Вид поля в форме |
| `customFields[][viewModeMobile]` | `string` | Вид поля в мобильном приложении |
| `customFields[][dictionary]` | `string` | Связанный словарь |
| `customFields[][defaultTyped]` | `custom handler result for (mixed)` | Типизированное значение по умолчанию |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/custom-fields/dictionaries

Получение списка справочников, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `custom_fields_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `choice` | `{not blank}[20\|50\|100\|250]}` |     |
| `page` | `string` | `{not blank}{range: {>=1}}}` |     |
| `filter` | `object (CustomDictionaryFilter)` |     |     |
| `filter[name]` | `string` |     | Название |
| `filter[code]` | `string` |     | Символьный код |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `customDictionaries[]` | `array of objects (CustomDictionary)` | Справочник |
| `customDictionaries[][name]` | `string` | Название |
| `customDictionaries[][code]` | `string` | Символьный код |
| `customDictionaries[][elements][]` | `array of objects (SerializedCustomDictionaryElement)` | Элемент справочника |
| `customDictionaries[][elements][][name]` | `string` | Название |
| `customDictionaries[][elements][][code]` | `string` | Символьный код |
| `customDictionaries[][elements][][ordering]` | `integer` | Сортировка |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/custom-fields/dictionaries/create

Создание справочника

Для доступа к методу необходимо разрешение `custom_fields_write`.

Поле `customDictionary[code]` задается только при создании справочника.

Поле `customDictionary[elements][][name]` обязательное.

Поле `customDictionary[elements][][ordering]` по умолчанию принимает значение `50`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customDictionary` | `object (SerializedCustomDictionary)` |     |     |
| `customDictionary[name]` | `string` | `{not blank}` | Название |
| `customDictionary[code]` | `string` | `{match: \|^[a-z][\w_-]+$\|}` | Символьный код |
| `customDictionary[elements][]` | `array of objects (SerializedCustomDictionaryElement)` | `{not blank}` | Элемент справочника |
| `customDictionary[elements][][name]` | `string` |     | Название |
| `customDictionary[elements][][code]` | `string` |     | Символьный код |
| `customDictionary[elements][][ordering]` | `integer` |     | Сортировка |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `code` | `string` | Символьный код |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Справочник успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/custom-fields/dictionaries/{code}

Получение информации о справочнике

Для доступа к методу необходимо разрешение `custom_fields_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `code` | `string` |     | Символьный код |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `customDictionary` | `object (CustomDictionary)` | Справочник |
| `customDictionary[name]` | `string` | Название |
| `customDictionary[code]` | `string` | Символьный код |
| `customDictionary[elements][]` | `array of objects (SerializedCustomDictionaryElement)` | Элемент справочника |
| `customDictionary[elements][][name]` | `string` | Название |
| `customDictionary[elements][][code]` | `string` | Символьный код |
| `customDictionary[elements][][ordering]` | `integer` | Сортировка |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Справочник не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/custom-fields/dictionaries/{code}/edit

Редактирование справочика

Для доступа к методу необходимо разрешение `custom_fields_write`.

Поле `customDictionary[code]` не редактируется.

Поле `customDictionary[elements][][name]` обязательное.

Поле `customDictionary[elements][][ordering]` по умолчанию принимает значение `50`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customDictionary` | `object (SerializedCustomDictionary)` |     |     |
| `customDictionary[name]` | `string` | `{not blank}` | Название |
| `customDictionary[elements][]` | `array of objects (SerializedCustomDictionaryElement)` | `{not blank}` | Элемент справочника |
| `customDictionary[elements][][name]` | `string` |     | Название |
| `customDictionary[elements][][code]` | `string` |     | Символьный код |
| `customDictionary[elements][][ordering]` | `integer` |     | Сортировка |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `code` | `string` | Символьный код |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Справочник не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/custom-fields/{entity}/create

Создание пользовательского поля

Для доступа к методу необходимо разрешение `custom_fields_write`.

Поля `{entity}`, `customField[type]`, `customField[code]` задаются только при создании пользовательского поля.

Если поле `customField[type] = dictionary`, в поле `customField[default]` требуется передавать код элемента справочника.

Доступные значения поля `customField[displayArea]` зависят от `{entity}.`

Если поле `{entity} = order`, поле `customField[displayArea]` может принимать следующие значения:

- `customer` – Покупатель
- `legal_details` – Юридические реквизиты
- `shipment` – Отгрузка
- `dimensions` – Размеры и вес
- `delivery` – Доставка
- `payment` – Оплата

Если поле `{entity} = customer`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные
- `delivery` – Доставка
- `legal_details` – Юридические реквизиты

Если поле `{entity} = customer_corporate`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные

Если поле `{entity} = company`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные
- `legal_details` – Юридические реквизиты

Поле `customField[required]` по умолчанию принимает значение `false`.

Поле `customField[inFilter]` по умолчанию принимает значение `true`.

Поле `customField[inList]` по умолчанию принимает значение `true`.

Поле `customField[inGroupActions]` по умолчанию принимает значение `false`.

Поле `customField[viewMode]` по умолчанию принимает значение `editable`.

Поле `customField[viewModeMobile]` по умолчанию принимает значение `editable`.

Поле `customField[ordering]` по умолчанию принимает значение `50`.

Если поле `customField[type] = dictionary`, поле `customField[dictionary]` является обязательным и содержит код связанного справочника. Заполняется только при создании пользовательского поля.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customField` | `object (SerializedCustomFieldApiDocModel)` |     |     |
| `customField[name]` | `string` | `{not blank}` | Название |
| `customField[type]` | `string` | `{not blank}[boolean\|date\|datetime\|dictionary\|email\|integer\|multiselect_dictionary\|numeric\|string\|text]}` | Тип поля |
| `customField[entity]` | `choice` | `{not blank}[customer\|loyalty_account\|order]}` | Поля для таблицы |
| `customField[code]` | `string` | `{match: \|^[a-z][\w_]+$\|}` | Символьный код |
| `customField[ordering]` | `integer` | `{range: {>=0}}` | Сортировка |
| `customField[displayArea]` | `string` | `[address\|customer\|delivery\|dimensions\|legal_details\|main_data\|payment\|shipment]` | Область отображения |
| `customField[viewMode]` | `string` | `[editable\|miss\|not_editable]` | Вид поля в форме |
| `customField[viewModeMobile]` | `string` | `[editable\|miss\|not_editable]` | Вид поля в мобильном приложении |
| `customField[required]` | `boolean` |     | Обязательное |
| `customField[inFilter]` | `boolean` |     | Доступно в фильтре |
| `customField[inList]` | `boolean` |     | Доступно в списке |
| `customField[inGroupActions]` | `boolean` |     | Доступно в групповых операциях |
| `customField[default]` | `string` |     | `deprecated` Значение по умолчанию |
| `customField[dictionary]` | `string` |     | Связанный словарь |
| `customField[defaultTyped]` | `custom handler result for (mixed)` |     | Типизированное значение по умолчанию |
| `entity` | `string` |     | Поле для таблицы |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `code` | `string` | Символьный код |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пользовательское поле успешно создано</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/custom-fields/{entity}/{code}

Получение информации о пользовательском поле

Для доступа к методу необходимо разрешение `custom_fields_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `code` | `string` |     | Символьный код |
| `entity` | `string` |     | Поле для таблицы |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `customField` | `object (CustomFieldApiDocModel)` |     |
| `customField[name]` | `string` | Название |
| `customField[code]` | `string` | Символьный код |
| `customField[required]` | `boolean` | Обязательное |
| `customField[inFilter]` | `boolean` | Доступно в фильтре |
| `customField[inList]` | `boolean` | Доступно в списке |
| `customField[inGroupActions]` | `boolean` | Доступно в групповых операциях |
| `customField[type]` | `string` | Тип поля |
| `customField[entity]` | `string` | Поля для таблицы |
| `customField[default]` | `string` | `deprecated` Значение по умолчанию |
| `customField[ordering]` | `integer` | Сортировка |
| `customField[displayArea]` | `string` | Область отображения |
| `customField[viewMode]` | `string` | Вид поля в форме |
| `customField[viewModeMobile]` | `string` | Вид поля в мобильном приложении |
| `customField[dictionary]` | `string` | Связанный словарь |
| `customField[defaultTyped]` | `custom handler result for (mixed)` | Типизированное значение по умолчанию |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пользовательское поле не найдено</p></li></ul></td></tr></tbody></table>

### POST /api/v5/custom-fields/{entity}/{code}/edit

Редактирование пользовательского поля

Для доступа к методу необходимо разрешение `custom_fields_write`.

Если поле `customField[type] = dictionary`, в поле `customField[default]` требуется передавать код элемента справочника.

Если поле `{entity} = order`, поле `customField[displayArea]` может принимать следующие значения:

- `customer` – Покупатель
- `legal_details` – Юридические реквизиты
- `shipment` – Отгрузка
- `dimensions` – Размеры и вес
- `delivery` – Доставка
- `payment` – Оплата

Если поле `{entity} = customer`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные
- `delivery` – Доставка
- `legal_details` – Юридические реквизиты

Если поле `{entity} = customer_corporate`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные

Если поле `{entity} = company`, поле `customField[displayArea]` может принимать следующие значения.

- `main_data` – Основные данные
- `legal_details` – Юридические реквизиты

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customField` | `object (SerializedCustomFieldApiDocModel)` |     |     |
| `customField[name]` | `string` | `{not blank}` | Название |
| `customField[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Сортировка |
| `customField[displayArea]` | `string` | `[address\|customer\|delivery\|dimensions\|legal_details\|main_data\|payment\|shipment]` | Область отображения |
| `customField[viewMode]` | `string` | `[editable\|miss\|not_editable]` | Вид поля в форме |
| `customField[viewModeMobile]` | `string` | `[editable\|miss\|not_editable]` | Вид поля в мобильном приложении |
| `customField[required]` | `boolean` |     | Обязательное |
| `customField[inFilter]` | `boolean` |     | Доступно в фильтре |
| `customField[inList]` | `boolean` |     | Доступно в списке |
| `customField[inGroupActions]` | `boolean` |     | Доступно в групповых операциях |
| `customField[default]` | `string` |     | `deprecated` Значение по умолчанию |
| `customField[defaultTyped]` | `custom handler result for (mixed)` |     | Типизированное значение по умолчанию |
| `entity` | `string` |     | Поле для таблицы |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `code` | `string` | Символьный код |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пользовательское поле не найдено</p></li></ul></td></tr></tbody></table>

## Клиенты

### GET /api/v5/customers

Получение списка клиентов, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтрах `filter[sourceName]`, `filter[mediumName]`, `filter[campaignName]`, `filter[keywordName]`, `filter[adContentName]` указывается название элементов.

В фильтрах `filter[managers][]` указывается массив внутренних ID элементов в системе.

В фильтре `filter[managerGroups][]` указывается массив символьных кодов элементов.

Фильтр `filter[discountCardNumber]` доступен, если включено поле «Дисконтная карта» в модуле «Лояльность».

В фильтрах `filter[ids][]` и `filter[externalIds][]` передается массив внутренних и внешних идентификаторов соответственно.

Фильтр `filter[classSegment]` позволяет получить сегменты RFM-анализа клиентов. Доступны следующие значения: `monetary[0..2]_recency[0..2]frequency[0..2]_recency[0..2]monetary[0..2]_frequency[0..2]`.

Фильтром `filter[name]` можно производить поиск по ФИО или телефону клиента.

С помощью фильтра `filter[customFields][]` можно производить поиск по значениям пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» и «Дата-время» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Для пользовательских полей типа `Целое число`, `Число`, `Дата` и `Дата-время` фильтрация осуществляется по диапазону, для остальных типов полей — по точному значению. Имя фильтра соответствует символьному коду поля. Пример: для поля типа `Дата` с символьным кодом `birth_date` доступны фильтры `filter[customFields][birth_date][min]` и `filter[customFields][birth_date][max]`. Для поля типа `Справочник` с символьным кодом `quality` доступен множественный фильтр `filter[customFields][quality][]`.

В фильтре `filter[attachments]` можно указать одно из трех значений:

- `1` - возвращает клиентов, у которых нет прикрепленных файлов;
- `2` - возвращает клиентов, у которых есть прикрепленные файлы;
- `3` - возвращает клиентов, у которых есть прикрепленные файлы и вложения к письмам.

В фильтре `filter[tasksCounts]` можно указать одно из трех значений:

- `1` - возвращает клиентов, у которых нет невыполненных задач;
- `2` - возвращает клиентов, у которых есть какие-либо невыполненные задачи (как просроченные, так и не просроченные);
- `3` - возвращает только тех клиентов, у которых среди невыполненных задач есть просроченные.

В фильтре `filter[mgChannels]` указывается массив внутренних ID каналов в системе. Фильтр выбирает клиентов с чатами в заданных каналах.

C помощью `filter[tags][]` и `filter[attachedTags][]` можно фильтровать клиентов по тегам.

При установленном фильтре `filter[tags][]` система вернёт клиентов у которых есть все перечисленные в списке теги, то есть при фильтрации используется условие `И`.

При установленном фильтре `filter[attachedTags][]` система вернёт клиентов у которых один из указанных в списке тегов является прикреплённым, то есть при фильтрации используется условие `ИЛИ`.

В значениях фильтров `filter[tags][]` и `filter[attachedTags][]` не должно быть дубликатов, пустых значений и тегов содержащих более двух идущих подряд пробелов. В противном случае запрос завершится соответствующей ошибкой.

Поля `personalDiscount`, `cumulativeDiscount` и `discountCardNumber` возвращаются, если они включены в настройках.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID клиентов |
| `filter[externalIds][]` | `array of strings` |     | Массив externalID клиентов |
| `filter[name]` | `string` | `{length: {max: 255}}` | Клиент |
| `filter[city]` | `string` | `{length: {max: 255}}` | Город |
| `filter[region]` | `string` | `{length: {max: 255}}` | Регион |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[managers][]` | `array of integers` |     | Менеджеры |
| `filter[managerGroups][]` | `array of strings` |     | Группы менеджеров |
| `filter[notes]` | `string` |     | Заметки |
| `filter[vip]` | `boolean` |     | Важный клиент |
| `filter[bad]` | `boolean` |     | Плохой клиент |
| `filter[discountCardNumber]` | `string` | `{length: {max: 255}}` | Номер дисконтной карты |
| `filter[attachments]` | `integer` | `[1\|2\|3]` | Прикрепленные объекты (вложения) |
| `filter[tasksCounts]` | `integer` | `[1\|2\|3]` | Задачи |
| `filter[email]` | `string` | `{length: {max: 255}}` | E-mail |
| `filter[contragentName]` | `string` | `{length: {max: 255}}` | Полное наименование |
| `filter[contragentTypes][]` | `array of strings` | `{choice of [enterpreneur\|individual\|legal-entity]}` | Типы контрагента |
| `filter[contragentInn]` | `string` | `{match: /\d+/}` | ИНН |
| `filter[contragentKpp]` | `string` | `{match: /\d+/}` | КПП |
| `filter[contragentBik]` | `string` | `{match: /\d+/}` | БИК банка |
| `filter[contragentCorrAccount]` | `string` | `{match: /\d+/}` | Корр. счет банка |
| `filter[contragentBankAccount]` | `string` | `{match: /\d+/}` | Расчетный счет |
| `filter[classSegment]` | `string` | `{match: /^(monetary\|recency\|frequency)[0-2]_(recency\|frequency\|monetary)[0-2]$/}` | Сегмент |
| `filter[minOrdersCount]` | `integer` |     | Количество заказов (от) |
| `filter[maxOrdersCount]` | `integer` |     | Количество заказов (до) |
| `filter[minAverageSumm]` | `integer` |     | Средний чек (от) |
| `filter[maxAverageSumm]` | `integer` |     | Средний чек (до) |
| `filter[minTotalSumm]` | `integer` |     | Сумма по заказам (от) |
| `filter[maxTotalSumm]` | `integer` |     | Сумма по заказам (до) |
| `filter[minCostSumm]` | `integer` |     | Сумма расходов по заказам (от) |
| `filter[maxCostSumm]` | `integer` |     | Сумма расходов по заказам (до) |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Дата регистрации (от) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Дата регистрации (до) |
| `filter[firstOrderFrom]` | `DateTime` | `Y-m-d` | Первый заказ (от) |
| `filter[firstOrderTo]` | `DateTime` | `Y-m-d` | Первый заказ (до) |
| `filter[lastOrderFrom]` | `DateTime` | `Y-m-d` | Последний заказ (от) |
| `filter[lastOrderTo]` | `DateTime` | `Y-m-d` | Последний заказ (до) |
| `filter[customFields]` | `array` |     | Пользовательские поля |
| `filter[sex]` | `string` | `[female\|female_with_presumable\|male\|male_with_presumable]` | Пол |
| `filter[isContact]` | `boolean` |     | Клиент является контактным лицом |
| `filter[subscriptions][]` | `array of objects (CustomerFilterCustomerSubscriptionData)` |     | Фильтр по подпискам пользователя |
| `filter[subscriptions][][channel]` | `string` |     | Канал категории подписки |
| `filter[subscriptions][][subscription]` | `string` |     | Код категории подписки |
| `filter[subscriptions][][subscribed]` | `boolean` |     | Флаг подписки |
| `filter[online]` | `boolean` |     | Клиент на сайте |
| `filter[segment]` | `string` |     | Сегмент |
| `filter[commentary]` | `string` |     | Комментарий оператора |
| `filter[browserId]` | `string` |     | Идентификатор устройства в Collector |
| `filter[mgChannels][]` | `array of integers` |     | Каналы чатов |
| `filter[sourceName]` | `string` | `{length: {max: 255}}` | Источник |
| `filter[mediumName]` | `string` | `{length: {max: 255}}` | Канал |
| `filter[campaignName]` | `string` | `{length: {max: 255}}` | Кампания |
| `filter[keywordName]` | `string` |     | Ключевое слово |
| `filter[adContentName]` | `string` |     | Содержание кампании |
| `filter[tags][]` | `array of strings` |     | Теги |
| `filter[attachedTags][]` | `array of strings` |     | Список прикреплённых тегов (или) |
| `filter[countries][]` | `array of strings` |     | Страны |
| `filter[abandonedCart]` | `boolean` |     |     |
| `filter[emailMarketingUnsubscribed]` | `boolean` |     | `deprecated` Отписан от email рассылок |
| `filter[mgCustomerId]` | `string` |     | Идентификатор клиента MessageGateway |
| `filter[firstWebVisitFrom]` | `DateTime` | `Y-m-d` | Первое посещение (от) |
| `filter[firstWebVisitTo]` | `DateTime` | `Y-m-d` | Первое посещение (до) |
| `filter[lastWebVisitFrom]` | `DateTime` | `Y-m-d` | Последнее посещение (от) |
| `filter[lastWebVisitTo]` | `DateTime` | `Y-m-d` | Последнее посещение (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `customers[]` | `array of objects (Customer)` | Клиент |
| `customers[][type]` | `string` | Тип клиента |
| `customers[][id]` | `integer` | ID клиента |
| `customers[][externalId]` | `string` | Внешний ID клиента |
| `customers[][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `customers[][createdAt]` | `DateTime` | Создан |
| `customers[][managerId]` | `integer` | Менеджер клиента |
| `customers[][vip]` | `boolean` | Важный клиент |
| `customers[][bad]` | `boolean` | Плохой клиент |
| `customers[][site]` | `string` | Магазин, с которого пришел клиент |
| `customers[][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `customers[][contragent][contragentType]` | `string` | Тип контрагента |
| `customers[][contragent][legalName]` | `string` | Полное наименование |
| `customers[][contragent][legalAddress]` | `string` | Адрес регистрации |
| `customers[][contragent][INN]` | `string` | ИНН |
| `customers[][contragent][OKPO]` | `string` | ОКПО |
| `customers[][contragent][KPP]` | `string` | КПП |
| `customers[][contragent][OGRN]` | `string` | ОГРН |
| `customers[][contragent][OGRNIP]` | `string` | ОГРНИП |
| `customers[][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `customers[][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `customers[][contragent][BIK]` | `string` | БИК |
| `customers[][contragent][bank]` | `string` | Банк |
| `customers[][contragent][bankAddress]` | `string` | Адрес банка |
| `customers[][contragent][corrAccount]` | `string` | Корр. счёт |
| `customers[][contragent][bankAccount]` | `string` | Расчётный счёт |
| `customers[][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `customers[][tags][][color]` | `string` |     |
| `customers[][tags][][name]` | `string` |     |
| `customers[][tags][][colorCode]` | `string` |     |
| `customers[][tags][][attached]` | `boolean` |     |
| `customers[][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `customers[][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `customers[][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `customers[][personalDiscount]` | `double` | Персональная скидка |
| `customers[][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `customers[][discountCardNumber]` | `string` | Номер дисконтной карты |
| `customers[][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `customers[][marginSumm]` | `float` | LTV (в базовой валюте) |
| `customers[][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `customers[][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `customers[][ordersCount]` | `integer` | Количество заказов |
| `customers[][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `customers[][address]` | `object (CustomerAddress)` | Адрес клиента |
| `customers[][address][id]` | `integer` | ID адреса |
| `customers[][address][index]` | `string` | Индекс |
| `customers[][address][countryIso]` | `string` | ISO код страны |
| `customers[][address][region]` | `string` | Регион |
| `customers[][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `customers[][address][city]` | `string` | Город |
| `customers[][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `customers[][address][cityType]` | `string` | Тип населенного пункта |
| `customers[][address][street]` | `string` | Улица |
| `customers[][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `customers[][address][streetType]` | `string` | Тип улицы |
| `customers[][address][building]` | `string` | Дом |
| `customers[][address][flat]` | `string` | Номер квартиры/офиса |
| `customers[][address][floor]` | `integer` | Этаж |
| `customers[][address][block]` | `integer` | Подъезд |
| `customers[][address][house]` | `string` | Строение |
| `customers[][address][housing]` | `string` | Корпус |
| `customers[][address][metro]` | `string` | Метро |
| `customers[][address][notes]` | `string` | Примечания к адресу |
| `customers[][address][text]` | `string` | Адрес в текстовом виде |
| `customers[][address][externalId]` | `string` | Внешний ID |
| `customers[][address][name]` | `string` | Наменование адреса |
| `customers[][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `customers[][segments][][id]` | `integer` | Внутренний ID сегмента |
| `customers[][segments][][code]` | `string` | Символьный код |
| `customers[][segments][][name]` | `string` | Название сегмента |
| `customers[][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `customers[][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `customers[][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `customers[][segments][][active]` | `boolean` | Активность сегмента |
| `customers[][maturationTime]` | `integer` | Время «созревания», в секундах |
| `customers[][firstName]` | `string` | Имя |
| `customers[][lastName]` | `string` | Фамилия |
| `customers[][patronymic]` | `string` | Отчество |
| `customers[][sex]` | `string` | Пол |
| `customers[][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `customers[][email]` | `string` | E-mail |
| `customers[][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `customers[][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `customers[][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `customers[][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `customers[][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `customers[][customerSubscriptions][][subscription][name]` | `string` | Название |
| `customers[][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `customers[][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `customers[][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `customers[][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `customers[][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `customers[][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `customers[][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `customers[][phones][][number]` | `string` | Номер телефона |
| `customers[][birthday]` | `DateTime` | День рождения |
| `customers[][source]` | `object (SerializedSource)` | Источник клиента |
| `customers[][source][source]` | `string` | Источник |
| `customers[][source][medium]` | `string` | Канал |
| `customers[][source][campaign]` | `string` | Кампания |
| `customers[][source][keyword]` | `string` | Ключевое слово |
| `customers[][source][content]` | `string` | Содержание кампании |
| `customers[][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `customers[][mgCustomers][][id]` | `integer` | ID клиента |
| `customers[][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `customers[][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `customers[][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `customers[][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `customers[][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `customers[][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `customers[][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `customers[][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `customers[][photoUrl]` | `string` | URL фотографии |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/combine

Объединение клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Позволяет объединить клиентов.

При конфликте полей сохранятся только данные клиента `resultCustomer`, данные прочих клиентов по конкретному полю будут удалены. Все телефоны будут добавлены клиенту `resultCustomer`. Клиенты из параметра `customers` будут безвозвратно удалены.

В ходе объединения клиентов будут объединены связанные с ними данные.

Одновременно можно объединять до 50 клиентов.

Операция выполняется в асинхронном режиме. Успешный ответ `success=true` означает, что операция принята к выполнению, но еще не завершена. Фактический результат операции можно отслеживать при помощи метода `/api/v5/customers/history` по тем клиентам, которые будут удалены в процессе объединения (параметр ответа `history[][combinedTo]`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `resultCustomer` | `object (SerializedCustomerReference)` | `{not blank}` | Клиент, в которого произойдет объединение |
| `resultCustomer[id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID клиента |
| `customers[]` | `array of objects (SerializedCustomerReference)` |     |     |
| `customers[][id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/create

Создание клиента

Для доступа к методу необходимо разрешение `customer_write`.

Метод создает клиента и возвращает внутренний ID созданного клиента.

Если не указывать `customer[createdAt]`, то будет использовано текущее время в качестве даты/времени регистрации клиента.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

Поля `customer[personalDiscount]` и `customer[discountCardNumber]` принимаются, если они включены в настройках.

В поле `customer[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Адрес клиента `customer[address]` можно указывать либо в строковом виде в поле `customer[address][text]`, либо в подробном виде, заполняя все поля кроме `customer[address][text]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `customer` | `object (SerializedCustomer)` |     |     |
| `customer[externalId]` | `string` |     | Внешний ID клиента |
| `customer[isContact]` | `boolean` |     | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `customer[createdAt]` | `DateTime` |     | Создан |
| `customer[vip]` | `boolean` |     | Важный клиент |
| `customer[bad]` | `boolean` |     | Плохой клиент |
| `customer[contragent]` | `object (CustomerContragent)` |     | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `customer[contragent][contragentType]` | `string` |     | Тип контрагента |
| `customer[contragent][legalName]` | `string` |     | Полное наименование |
| `customer[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `customer[contragent][INN]` | `string` |     | ИНН |
| `customer[contragent][OKPO]` | `string` |     | ОКПО |
| `customer[contragent][KPP]` | `string` |     | КПП |
| `customer[contragent][OGRN]` | `string` |     | ОГРН |
| `customer[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `customer[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `customer[contragent][certificateDate]` | `DateTime` |     | Дата свидетельства |
| `customer[contragent][BIK]` | `string` |     | БИК |
| `customer[contragent][bank]` | `string` |     | Банк |
| `customer[contragent][bankAddress]` | `string` |     | Адрес банка |
| `customer[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `customer[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `customer[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customer[personalDiscount]` | `double` |     | Персональная скидка |
| `customer[discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customer[address]` | `object (CustomerAddress)` |     | Адрес клиента |
| `customer[address][index]` | `string` |     | Индекс |
| `customer[address][countryIso]` | `string` |     | ISO код страны |
| `customer[address][region]` | `string` |     | Регион |
| `customer[address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `customer[address][city]` | `string` |     | Город |
| `customer[address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `customer[address][cityType]` | `string` |     | Тип населенного пункта |
| `customer[address][street]` | `string` |     | Улица |
| `customer[address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `customer[address][streetType]` | `string` |     | Тип улицы |
| `customer[address][building]` | `string` |     | Дом |
| `customer[address][flat]` | `string` |     | Номер квартиры/офиса |
| `customer[address][floor]` | `integer` |     | Этаж |
| `customer[address][block]` | `integer` |     | Подъезд |
| `customer[address][house]` | `string` |     | Строение |
| `customer[address][housing]` | `string` |     | Корпус |
| `customer[address][metro]` | `string` |     | Метро |
| `customer[address][notes]` | `string` |     | Примечания к адресу |
| `customer[address][text]` | `string` |     | Адрес в текстовом виде |
| `customer[firstName]` | `string` |     | Имя |
| `customer[lastName]` | `string` |     | Фамилия |
| `customer[patronymic]` | `string` |     | Отчество |
| `customer[email]` | `string` |     | E-mail |
| `customer[emailMarketingUnsubscribedAt]` | `DateTime` |     | `deprecated` Дата отписки от email рассылок |
| `customer[phones][]` | `array of objects (CustomerPhone)` |     | Телефоны |
| `customer[phones][][number]` | `string` |     | Номер телефона |
| `customer[birthday]` | `DateTime` |     | День рождения |
| `customer[photoUrl]` | `string` |     | URL фотографии |
| `customer[managerId]` | `integer` |     | Менеджер клиента |
| `customer[sex]` | `string` |     | Пол |
| `customer[source]` | `object (SerializedSource)` |     | Источник клиента |
| `customer[source][source]` | `string` |     | Источник |
| `customer[source][medium]` | `string` |     | Канал |
| `customer[source][campaign]` | `string` |     | Кампания |
| `customer[source][keyword]` | `string` |     | Ключевое слово |
| `customer[source][content]` | `string` |     | Содержание кампании |
| `customer[mgCustomerId]` | `object (MGCustomer)` |     | Идентификатор клиента MessageGateway |
| `customer[subscribed]` | `boolean` |     | Статус подписки на маркетинговые рассылки писем |
| `customer[tags][]` | `array of strings` |     | Теги |
| `customer[attachedTag]` | `string` |     | Прикреплённый тег |
| `customer[browserId]` | `string` |     | Идентификатор устройства в Collector |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного клиента |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании клиента</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/fix-external-ids

Массовая запись внешних ID клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Данный метод полезен в случае обратной синхронизации клиентов, которые исходно оформлены в системе.

Магазин запрашивает клиентов, созданных в системе, и создает их в своей базе. При создании клиентов в магазине формируются собственные ID клиентов (`externalId` клиентов в нотации системы). Сразу после создания клиентов интернет-магазин вызывает метод `/api/v*/customers/fix-external-ids`, сохраняя в системе собственные ID клиентов.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customers[]` | `array of objects (FixExternalRow)` |     | Идентификаторы загруженных объектов |
| `customers[][id]` | `integer` | `{not blank}{range: {>=1, <=2147483647}}}` | Внутренний ID |
| `customers[][externalId]` | `string` | `{not blank}` | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers/history

Получение истории изменения клиентов

Для доступа к методу необходимо разрешение `customer_read`.

Возвращает изменения в клиентах, произведенные в указанный диапазон дат (используя фильтры `filter[startDate]` и `filter[endDate]`), либо инкрементальные изменения (используя `filter[sinceId]`). При реализации постоянной трансляции изменений во внешнюю систему ​**рекомендуется**​ использовать подход с забором инкрементальных изменений через `filter[sinceId]` передавая `id` последней полученной записи истории.

Для записей создания и удаления клиента возвращается полный набор полей в контексте `customer`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке. Для постраничного перебора записей истории ​**необходимо**​ использовать `filter[sinceId]`. Параметр `page` использовать не рекомендуется.

Более подробно о работе с историей вы можете ознакомиться в [отдельной статье документации](https://docs.retailcrm.ru/Developers/API/APIFeatures/WorkingHistoryAPI).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerHistoryFilterV4Type)` |     |     |
| `filter[customerId]` | `integer` | `{range: {>=0, <=4294967295}}` | ID клиента |
| `filter[sinceId]` | `integer` | `{range: {>=0, <=4294967295}}` | Начиная с ID истории клиентов |
| `filter[customerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID клиента |
| `filter[startDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (от) |
| `filter[endDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `generatedAt` | `DateTime` | Время формирования ответа |
| `history[]` | `array of objects (CustomerHistory)` |     |
| `history[][id]` | `integer` | Внутренний идентификатор записи в истории |
| `history[][createdAt]` | `DateTime` | Дата внесения изменения |
| `history[][created]` | `boolean` | Признак создания сущности |
| `history[][deleted]` | `boolean` | Признак удаления сущности |
| `history[][source]` | `string` | Источник изменения |
| `history[][user]` | `object (User)` | Пользователь |
| `history[][user][id]` | `integer` | ID пользователя |
| `history[][field]` | `string` | Имя изменившегося поля |
| `history[][oldValue]` | `custom handler result for (mixed)` | Старое значение свойства |
| `history[][newValue]` | `custom handler result for (mixed)` | Новое значение свойства |
| `history[][apiKey]` | `object (ApiKey)` | Информация о ключе api, использовавшемся для этого изменения |
| `history[][apiKey][current]` | `boolean` | Изменение было сделано с помощью ключа, используемого в данный момент |
| `history[][apiKey][id]` | `integer` | ID API-ключа |
| `history[][customer]` | `object (Customer)` | Клиент |
| `history[][customer][id]` | `integer` | ID клиента |
| `history[][customer][externalId]` | `string` | Внешний ID клиента |
| `history[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `history[][address]` | `object (CustomerAddressWithIsMain)` | Адрес клиента |
| `history[][address][id]` | `integer` | ID адреса клиента |
| `history[][address][externalId]` | `string` | Внешний ID |
| `history[][address][name]` | `string` | Наменование адреса |
| `history[][address][isMain]` | `boolean` | Адрес клиента является основным |
| `history[][combinedTo]` | `object (Customer)` | Информация о клиенте, который получился после объединения с текущим клиентом |
| `history[][subscription]` | `object (Subscription)` | Категория подписки |
| `history[][subscription][channel]` | `string` | Канал |
| `history[][subscription][name]` | `string` | Название |
| `history[][subscription][code]` | `string` | Символьный код |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers/notes

Получение заметок

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтре `filter[ids][]` передаётся массив внутренних идентификаторов заметок.

В фильтрах `filter[customerIds][]` и `filter[customerExternalIds][]` передаются массивы внутренних и внешних идентификаторов клиентов.

В фильтре `filter[managerIds][]` передаётся массив внутренних идентификаторов менеджеров.

Фильтр `filter[text]` позволяет получить заметки по частичному совпадению заданного текста.

Фильтры `filter[createdAtFrom]` и `filter[createdAtTo]` позволяют получить заметки созданные в заданном интервале времени.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerNoteFilter)` |     |     |
| `filter[ids]` | `array of integers` |     | ID заметок |
| `filter[customerIds]` | `array of integers` |     | Внутренние ID клиентов |
| `filter[customerExternalIds]` | `array of strings` |     | Внешние ID клиентов |
| `filter[managerIds]` | `array of integers` |     | ID менеджеров |
| `filter[text]` | `string` |     | Текст заметки |
| `filter[createdAtFrom]` | `string` |     | Дата/время создания (от) |
| `filter[createdAtTo]` | `string` |     | Дата/время создания (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `notes[]` | `array of objects (CustomerNote)` | Заметка |
| `notes[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `notes[][customer][site]` | `string` | Символьный код магазина |
| `notes[][customer][id]` | `integer` | Внутренний ID клиента |
| `notes[][customer][externalId]` | `string` | Внешний ID клиента |
| `notes[][customer][type]` | `string` | Тип клиента |
| `notes[][managerId]` | `integer` | ID менеджера |
| `notes[][id]` | `integer` | ID заметки |
| `notes[][text]` | `string` | Текст заметки |
| `notes[][createdAt]` | `DateTime` | Дата/время создания |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/notes/create

Создание заметки

Для доступа к методу необходимо разрешение `customer_write`.

Метод создает заметку и в случае успеха возвращает её внутренний ID.

В поле `note[managerId]` можно указать внутренний ID менеджера к которому необходимо привязать создаваемую заметку.

В поле `note[text]` **необходимо** задать содержание заметки. Длина текста должна быть не менее 1 символа и не более 2000 символов. Допускается использовать HTML теги (`p`, `a`, `ul`, `ol`, `strong`, `em`, `blockquote`). При использовании в тексте HTML разметки важно соблюдать семантику и следить за наличием закрывающих тегов, иначе система выдаст ошибку.

В поле `note[customer]` **необходимо** задать клиента к которому будет привязана создаваемая заметка. Сделать это можно используя внутренний `note[customer][id]` либо внешний `note[customer][externalId]` ID клиента.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `note` | `object (SerializedCustomerNote)` |     | Заметка |
| `note[managerId]` | `integer` |     | Внутренний ID менеджера |
| `note[text]` | `string` |     | Текст заметки |
| `note[customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `note[customer][id]` | `integer` |     | Внутренний ID клиента |
| `note[customer][externalId]` | `string` |     | Внешний ID клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданной заметки |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании заметки</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/notes/{id}/delete

Удаление заметки

Для доступа к методу необходимо разрешение `customer_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID заметки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка успешно удалена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка не найдена</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/upload

Пакетная загрузка клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Метод позволяет загружать пакетно до 50 клиентов.

Подробную информацию по формату данных см. в описании метода `/api/v*/customers/create`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина, к которому относятся загружаемые клиенты |
| `customers[]` | `array of objects (SerializedCustomer)` |     |     |
| `customers[][externalId]` | `string` |     | Внешний ID клиента |
| `customers[][isContact]` | `boolean` |     | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `customers[][createdAt]` | `DateTime` |     | Создан |
| `customers[][vip]` | `boolean` |     | Важный клиент |
| `customers[][bad]` | `boolean` |     | Плохой клиент |
| `customers[][customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customers[][personalDiscount]` | `double` |     | Персональная скидка |
| `customers[][discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customers[][address]` | `object (CustomerAddress)` |     | Адрес клиента |
| `customers[][address][index]` | `string` |     | Индекс |
| `customers[][address][countryIso]` | `string` |     | ISO код страны |
| `customers[][address][region]` | `string` |     | Регион |
| `customers[][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `customers[][address][city]` | `string` |     | Город |
| `customers[][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `customers[][address][cityType]` | `string` |     | Тип населенного пункта |
| `customers[][address][street]` | `string` |     | Улица |
| `customers[][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `customers[][address][streetType]` | `string` |     | Тип улицы |
| `customers[][address][building]` | `string` |     | Дом |
| `customers[][address][flat]` | `string` |     | Номер квартиры/офиса |
| `customers[][address][floor]` | `integer` |     | Этаж |
| `customers[][address][block]` | `integer` |     | Подъезд |
| `customers[][address][house]` | `string` |     | Строение |
| `customers[][address][housing]` | `string` |     | Корпус |
| `customers[][address][metro]` | `string` |     | Метро |
| `customers[][address][notes]` | `string` |     | Примечания к адресу |
| `customers[][address][text]` | `string` |     | Адрес в текстовом виде |
| `customers[][firstName]` | `string` |     | Имя |
| `customers[][lastName]` | `string` |     | Фамилия |
| `customers[][patronymic]` | `string` |     | Отчество |
| `customers[][email]` | `string` |     | E-mail |
| `customers[][emailMarketingUnsubscribedAt]` | `DateTime` |     | `deprecated` Дата отписки от email рассылок |
| `customers[][phones][]` | `array of objects (CustomerPhone)` |     | Телефоны |
| `customers[][phones][][number]` | `string` |     | Номер телефона |
| `customers[][birthday]` | `DateTime` |     | День рождения |
| `customers[][photoUrl]` | `string` |     | URL фотографии |
| `customers[][managerId]` | `integer` |     | Менеджер клиента |
| `customers[][sex]` | `string` |     | Пол |
| `customers[][source]` | `object (SerializedSource)` |     | Источник клиента |
| `customers[][source][source]` | `string` |     | Источник |
| `customers[][source][medium]` | `string` |     | Канал |
| `customers[][source][campaign]` | `string` |     | Кампания |
| `customers[][source][keyword]` | `string` |     | Ключевое слово |
| `customers[][source][content]` | `string` |     | Содержание кампании |
| `customers[][mgCustomerId]` | `object (MGCustomer)` |     | Идентификатор клиента MessageGateway |
| `customers[][subscribed]` | `boolean` |     | Статус подписки на маркетинговые рассылки писем |
| `customers[][tags][]` | `array of strings` |     | Теги |
| `customers[][attachedTag]` | `string` |     | Прикреплённый тег |
| `customers[][browserId]` | `string` |     | Идентификатор устройства в Collector |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `uploadedCustomers[]` | `array of objects (FixExternalRow)` | Идентификаторы загруженных объектов |
| `uploadedCustomers[][id]` | `integer` | Внутренний ID |
| `uploadedCustomers[][externalId]` | `string` | Внешний ID |
| `failedCustomers[]` | `array of objects (EntityWithExternalId)` | Идентификаторы незагруженных объектов |
| `failedCustomers[][externalId]` | `string` | Внешний ID (при наличии) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Все клиенты успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Не передан массив клиентов, либо массив слишком большой</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть клиентов не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers/{externalId}

Получение информации о клиенте

Для доступа к методу необходимо разрешение `customer_read`.

Метод возвращает полную информацию по клиенту. Можно обращаться к клиенту как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

Пустые поля без значений не возвращаются.

В поле `managerId` возвращается внутренний ID сущности в системе.

Поля `personalDiscount`, `cumulativeDiscount` и `discountCardNumber` возвращаются, если они включены в настройках.

В поле `customFields` возвращается массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Если адрес клиента указывался в строковом виде, то он будет возвращен в `customer[address][text]`. Если адрес указывался в детальном виде, то будут возвращены все заполненные поля доставки, а в `customer[address][text]` будет находиться автоматически сформированное текстовое представление адреса.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalId` | `string` |     | ID клиента |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `customer` | `object (Customer)` | Клиент |
| `customer[type]` | `string` | Тип клиента |
| `customer[id]` | `integer` | ID клиента |
| `customer[externalId]` | `string` | Внешний ID клиента |
| `customer[isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `customer[createdAt]` | `DateTime` | Создан |
| `customer[managerId]` | `integer` | Менеджер клиента |
| `customer[vip]` | `boolean` | Важный клиент |
| `customer[bad]` | `boolean` | Плохой клиент |
| `customer[site]` | `string` | Магазин, с которого пришел клиент |
| `customer[contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `customer[contragent][contragentType]` | `string` | Тип контрагента |
| `customer[contragent][legalName]` | `string` | Полное наименование |
| `customer[contragent][legalAddress]` | `string` | Адрес регистрации |
| `customer[contragent][INN]` | `string` | ИНН |
| `customer[contragent][OKPO]` | `string` | ОКПО |
| `customer[contragent][KPP]` | `string` | КПП |
| `customer[contragent][OGRN]` | `string` | ОГРН |
| `customer[contragent][OGRNIP]` | `string` | ОГРНИП |
| `customer[contragent][certificateNumber]` | `string` | Номер свидетельства |
| `customer[contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `customer[contragent][BIK]` | `string` | БИК |
| `customer[contragent][bank]` | `string` | Банк |
| `customer[contragent][bankAddress]` | `string` | Адрес банка |
| `customer[contragent][corrAccount]` | `string` | Корр. счёт |
| `customer[contragent][bankAccount]` | `string` | Расчётный счёт |
| `customer[tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `customer[tags][][color]` | `string` |     |
| `customer[tags][][name]` | `string` |     |
| `customer[tags][][colorCode]` | `string` |     |
| `customer[tags][][attached]` | `boolean` |     |
| `customer[firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `customer[lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `customer[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `customer[personalDiscount]` | `double` | Персональная скидка |
| `customer[cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `customer[discountCardNumber]` | `string` | Номер дисконтной карты |
| `customer[avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `customer[marginSumm]` | `float` | LTV (в базовой валюте) |
| `customer[totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `customer[averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `customer[ordersCount]` | `integer` | Количество заказов |
| `customer[costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `customer[address]` | `object (CustomerAddress)` | Адрес клиента |
| `customer[address][id]` | `integer` | ID адреса |
| `customer[address][index]` | `string` | Индекс |
| `customer[address][countryIso]` | `string` | ISO код страны |
| `customer[address][region]` | `string` | Регион |
| `customer[address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `customer[address][city]` | `string` | Город |
| `customer[address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `customer[address][cityType]` | `string` | Тип населенного пункта |
| `customer[address][street]` | `string` | Улица |
| `customer[address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `customer[address][streetType]` | `string` | Тип улицы |
| `customer[address][building]` | `string` | Дом |
| `customer[address][flat]` | `string` | Номер квартиры/офиса |
| `customer[address][floor]` | `integer` | Этаж |
| `customer[address][block]` | `integer` | Подъезд |
| `customer[address][house]` | `string` | Строение |
| `customer[address][housing]` | `string` | Корпус |
| `customer[address][metro]` | `string` | Метро |
| `customer[address][notes]` | `string` | Примечания к адресу |
| `customer[address][text]` | `string` | Адрес в текстовом виде |
| `customer[address][externalId]` | `string` | Внешний ID |
| `customer[address][name]` | `string` | Наменование адреса |
| `customer[segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `customer[segments][][id]` | `integer` | Внутренний ID сегмента |
| `customer[segments][][code]` | `string` | Символьный код |
| `customer[segments][][name]` | `string` | Название сегмента |
| `customer[segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `customer[segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `customer[segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `customer[segments][][active]` | `boolean` | Активность сегмента |
| `customer[maturationTime]` | `integer` | Время «созревания», в секундах |
| `customer[firstName]` | `string` | Имя |
| `customer[lastName]` | `string` | Фамилия |
| `customer[patronymic]` | `string` | Отчество |
| `customer[sex]` | `string` | Пол |
| `customer[presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `customer[email]` | `string` | E-mail |
| `customer[emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `customer[customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `customer[customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `customer[customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `customer[customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `customer[customerSubscriptions][][subscription][name]` | `string` | Название |
| `customer[customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `customer[customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `customer[customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `customer[customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `customer[customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `customer[customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `customer[phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `customer[phones][][number]` | `string` | Номер телефона |
| `customer[birthday]` | `DateTime` | День рождения |
| `customer[source]` | `object (SerializedSource)` | Источник клиента |
| `customer[source][source]` | `string` | Источник |
| `customer[source][medium]` | `string` | Канал |
| `customer[source][campaign]` | `string` | Кампания |
| `customer[source][keyword]` | `string` | Ключевое слово |
| `customer[source][content]` | `string` | Содержание кампании |
| `customer[mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `customer[mgCustomers][][id]` | `integer` | ID клиента |
| `customer[mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `customer[mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `customer[mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `customer[mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `customer[mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `customer[mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `customer[mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `customer[mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `customer[photoUrl]` | `string` | URL фотографии |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент не найден (если клиент был удален в результате объединения, в поле "combinedTo" будут данные целевого клиента)</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/{externalId}/edit

Редактирование клиента

Для доступа к методу необходимо разрешение `customer_write`.

Метод позволяет вносить изменения в клиента. Можно обращаться к клиенту как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

В случае, если производится попытка отредактировать удаленного клиента, система возвращает в ответе `state=removed`.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

Поля `customer[personalDiscount]` и `customer[discountCardNumber]` принимаются, если они включены в настройках.

В поле `customer[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Адрес клиента `customer[address]` можно указывать либо в строковом виде в поле `customer[address][text]`, либо в подробном виде, заполняя все поля кроме `customer[address][text]`.

Поля `customer[addTags]` и `customer[removeTags]` нельзя использовать совместно с полем `customer[tags]`. Значения в этих полях также не должны содержать пробельных символов, не являющихся пробелами, заглавных букв и пробелов в начале и конце.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина |
| `customer` | `object (SerializedCustomer)` |     |     |
| `customer[externalId]` | `string` |     | Внешний ID клиента |
| `customer[isContact]` | `boolean` |     | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `customer[createdAt]` | `DateTime` |     | Создан |
| `customer[vip]` | `boolean` |     | Важный клиент |
| `customer[bad]` | `boolean` |     | Плохой клиент |
| `customer[contragent]` | `object (CustomerContragent)` |     | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `customer[contragent][contragentType]` | `string` |     | Тип контрагента |
| `customer[contragent][legalName]` | `string` |     | Полное наименование |
| `customer[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `customer[contragent][INN]` | `string` |     | ИНН |
| `customer[contragent][OKPO]` | `string` |     | ОКПО |
| `customer[contragent][KPP]` | `string` |     | КПП |
| `customer[contragent][OGRN]` | `string` |     | ОГРН |
| `customer[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `customer[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `customer[contragent][certificateDate]` | `DateTime` |     | Дата свидетельства |
| `customer[contragent][BIK]` | `string` |     | БИК |
| `customer[contragent][bank]` | `string` |     | Банк |
| `customer[contragent][bankAddress]` | `string` |     | Адрес банка |
| `customer[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `customer[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `customer[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customer[personalDiscount]` | `double` |     | Персональная скидка |
| `customer[discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customer[address]` | `object (CustomerAddress)` |     | Адрес клиента |
| `customer[address][index]` | `string` |     | Индекс |
| `customer[address][countryIso]` | `string` |     | ISO код страны |
| `customer[address][region]` | `string` |     | Регион |
| `customer[address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `customer[address][city]` | `string` |     | Город |
| `customer[address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `customer[address][cityType]` | `string` |     | Тип населенного пункта |
| `customer[address][street]` | `string` |     | Улица |
| `customer[address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `customer[address][streetType]` | `string` |     | Тип улицы |
| `customer[address][building]` | `string` |     | Дом |
| `customer[address][flat]` | `string` |     | Номер квартиры/офиса |
| `customer[address][floor]` | `integer` |     | Этаж |
| `customer[address][block]` | `integer` |     | Подъезд |
| `customer[address][house]` | `string` |     | Строение |
| `customer[address][housing]` | `string` |     | Корпус |
| `customer[address][metro]` | `string` |     | Метро |
| `customer[address][notes]` | `string` |     | Примечания к адресу |
| `customer[address][text]` | `string` |     | Адрес в текстовом виде |
| `customer[firstName]` | `string` |     | Имя |
| `customer[lastName]` | `string` |     | Фамилия |
| `customer[patronymic]` | `string` |     | Отчество |
| `customer[email]` | `string` |     | E-mail |
| `customer[emailMarketingUnsubscribedAt]` | `DateTime` |     | `deprecated` Дата отписки от email рассылок |
| `customer[phones][]` | `array of objects (CustomerPhone)` |     | Телефоны |
| `customer[phones][][number]` | `string` |     | Номер телефона |
| `customer[birthday]` | `DateTime` |     | День рождения |
| `customer[photoUrl]` | `string` |     | URL фотографии |
| `customer[managerId]` | `integer` |     | Менеджер клиента |
| `customer[sex]` | `string` |     | Пол |
| `customer[source]` | `object (SerializedSource)` |     | Источник клиента |
| `customer[source][source]` | `string` |     | Источник |
| `customer[source][medium]` | `string` |     | Канал |
| `customer[source][campaign]` | `string` |     | Кампания |
| `customer[source][keyword]` | `string` |     | Ключевое слово |
| `customer[source][content]` | `string` |     | Содержание кампании |
| `customer[mgCustomerId]` | `object (MGCustomer)` |     | Идентификатор клиента MessageGateway |
| `customer[subscribed]` | `boolean` |     | Статус подписки на маркетинговые рассылки писем |
| `customer[addTags][]` | `array of strings` |     | Добавление тегов |
| `customer[removeTags][]` | `array of strings` |     | Удаление тегов |
| `customer[tags][]` | `array of strings` |     | Теги |
| `customer[attachedTag]` | `string` |     | Прикреплённый тег |
| `customer[browserId]` | `string` |     | Идентификатор устройства в Collector |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID клиента |
| `state` | `string` | Состояние клиента (по умолчанию не возвращается) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении клиента</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers/{externalId}/subscriptions

Подписка/отписка клиента на рассылки

Для доступа к методу необходимо разрешение `customer_write`.

Метод позволяет управлять подписками клиентов на рассылки.

В поле `customer[subscriptions]` передается массив подписок клиента на рассылки.

Если поле `customer[subscriptions][][subscription]` не передано, то производится подписка или отписка для всех типов подписки канала `customer[subscriptions][][channel]`.

В поле `customer[subscriptions][][messageId]` передается идентификатор сообщения, из которого клиент произвел подписку/отписку. Если это сообщение из чата, ожидается идентификатор сообщение в MG.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина |
| `subscriptions[]` | `array of objects (SerializedSubscription)` |     |     |
| `subscriptions[][channel]` | `string` | `{not blank}[email\|sms\|waba]}` | Канал подписки |
| `subscriptions[][subscription]` | `string` |     | Код категории подписки |
| `subscriptions[][active]` | `boolean` | `{not blank}` | Флаг подписки (подписан/отписан) |
| `subscriptions[][messageId]` | `integer` |     | Идентификатор сообщения, с которым взаимодействовал клиент |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Подписка на рассылку успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении подписки на рассылку</p></li></ul></td></tr></tbody></table>

## Корпоративные клиенты

### GET /api/v5/customers-corporate

Получение списка корпоративных клиентов, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтрах `filter[managers][]` указывается массив внутренних ID элементов в системе.

В фильтре `filter[managerGroups][]` указывается массив символьных кодов элементов.

Фильтр `filter[discountCardNumber]` доступен, если включено поле «Дисконтная карта» в модуле «Лояльность».

В фильтрах `filter[ids][]` и `filter[externalIds][]` передается массив внутренних и внешних идентификаторов соответственно.

Фильтром `filter[name]` можно производить поиск по наименованию корпоративного клиента.

С помощью фильтра `filter[customFields][]` можно производить поиск по значениям пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Для пользовательских полей типа `Целое число`, `Число` и `Дата` фильтрация осуществляется по диапазону, для остальных типов полей — по точному значению. Имя фильтра соответствует символьному коду поля. Пример: для поля типа `Дата` с символьным кодом `birth_date` доступны фильтры `filter[customFields][birth_date][min]` и `filter[customFields][birth_date][max]`. Для поля типа `Справочник` с символьным кодом `quality` доступен множественный фильтр `filter[customFields][quality][]`.

В фильтре `filter[attachments]` можно указать одно из трех значений:

- `1` - возвращает клиентов, у которых нет прикрепленных файлов;
- `2` - возвращает клиентов, у которых есть прикрепленные файлы;
- `3` - возвращает клиентов, у которых есть прикрепленные файлы и вложения к письмам.

В фильтре `filter[tasksCounts]` можно указать одно из трех значений:

- `1` - возвращает клиентов, у которых нет невыполненных задач;
- `2` - возвращает клиентов, у которых есть какие-либо невыполненные задачи (как просроченные, так и не просроченные);
- `3` - возвращает только тех клиентов, у которых среди невыполненных задач есть просроченные.

Поля `personalDiscount`, `cumulativeDiscount` и `discountCardNumber` возвращаются, если они включены в настройках.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerCorporateApiFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID клиентов |
| `filter[externalIds][]` | `array of strings` |     | Массив externalID клиентов |
| `filter[name]` | `string` | `{length: {max: 255}}` | Клиент |
| `filter[city]` | `string` | `{length: {max: 255}}` | Город |
| `filter[region]` | `string` | `{length: {max: 255}}` | Регион |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[managers][]` | `array of integers` |     | Менеджеры |
| `filter[managerGroups][]` | `array of strings` |     | Группы менеджеров |
| `filter[notes]` | `string` |     | Заметки |
| `filter[vip]` | `boolean` |     | Важный клиент |
| `filter[bad]` | `boolean` |     | Плохой клиент |
| `filter[discountCardNumber]` | `string` | `{length: {max: 255}}` | Номер дисконтной карты |
| `filter[attachments]` | `integer` | `[1\|2\|3]` | Прикрепленные файлы |
| `filter[tasksCounts]` | `integer` | `[1\|2\|3]` | Задачи |
| `filter[email]` | `string` | `{length: {max: 255}}` | E-mail контактного лица |
| `filter[contragentName]` | `string` | `{length: {max: 255}}` | Полное наименование |
| `filter[contragentTypes][]` | `array of strings` | `{choice of [enterpreneur\|legal-entity]}` | Типы контрагента |
| `filter[contragentInn]` | `string` | `{match: /\d+/}` | ИНН |
| `filter[contragentKpp]` | `string` | `{match: /\d+/}` | КПП |
| `filter[contragentBik]` | `string` | `{match: /\d+/}` | БИК банка |
| `filter[contragentCorrAccount]` | `string` | `{match: /\d+/}` | Корр. счет банка |
| `filter[contragentBankAccount]` | `string` | `{match: /\d+/}` | Расчетный счет |
| `filter[classSegment]` | `string` | `{match: /^(monetary\|recency\|frequency)[0-2]_(recency\|frequency\|monetary)[0-2]$/}` | Сегмент |
| `filter[minOrdersCount]` | `integer` |     | Количество заказов (от) |
| `filter[maxOrdersCount]` | `integer` |     | Количество заказов (до) |
| `filter[minAverageSumm]` | `integer` |     | Средний чек (от) |
| `filter[maxAverageSumm]` | `integer` |     | Средний чек (до) |
| `filter[minTotalSumm]` | `integer` |     | Сумма по заказам (от) |
| `filter[maxTotalSumm]` | `integer` |     | Сумма по заказам (до) |
| `filter[minCostSumm]` | `integer` |     | Сумма расходов по заказам (от) |
| `filter[maxCostSumm]` | `integer` |     | Сумма расходов по заказам (до) |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Дата регистрации (от) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Дата регистрации (до) |
| `filter[firstOrderFrom]` | `DateTime` | `Y-m-d` | Первый заказ (от) |
| `filter[firstOrderTo]` | `DateTime` | `Y-m-d` | Первый заказ (до) |
| `filter[lastOrderFrom]` | `DateTime` | `Y-m-d` | Последний заказ (от) |
| `filter[lastOrderTo]` | `DateTime` | `Y-m-d` | Последний заказ (до) |
| `filter[customFields]` | `array` |     | Пользовательские поля |
| `filter[nickName][]` | `array of strings` |     | Наименование |
| `filter[contactName]` | `string` |     | ФИО или телефон |
| `filter[addressName]` | `string` |     | Название адреса |
| `filter[phone]` | `string` |     | Телефон |
| `filter[companyCustomFields]` | `array` |     |     |
| `filter[contactIds][]` | `array of integers` |     | Массив ID контактных лиц |
| `filter[companyName]` | `string` |     | Название компании |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `customersCorporate[]` | `array of objects (CustomerCorporate)` | Корпоративный клиент |
| `customersCorporate[][type]` | `string` | Тип клиента |
| `customersCorporate[][id]` | `integer` | ID корпоративного клиента |
| `customersCorporate[][externalId]` | `string` | Внешний ID корпоративного клиента |
| `customersCorporate[][mainAddress]` | `object (EntityWithExternalIdNameOutput)` | Основной адрес корпоративного клиента |
| `customersCorporate[][mainAddress][id]` | `integer` | ID  |
| `customersCorporate[][mainAddress][externalId]` | `string` | Внешний ID |
| `customersCorporate[][mainAddress][name]` | `string` | Название |
| `customersCorporate[][createdAt]` | `DateTime` | Создан |
| `customersCorporate[][managerId]` | `integer` | Менеджер корпоративного клиента |
| `customersCorporate[][vip]` | `boolean` | Важный клиент |
| `customersCorporate[][bad]` | `boolean` | Плохой клиент |
| `customersCorporate[][site]` | `string` | Магазин, с которого пришел клиент |
| `customersCorporate[][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `customersCorporate[][tags][][color]` | `string` |     |
| `customersCorporate[][tags][][name]` | `string` |     |
| `customersCorporate[][tags][][colorCode]` | `string` |     |
| `customersCorporate[][tags][][attached]` | `boolean` |     |
| `customersCorporate[][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `customersCorporate[][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `customersCorporate[][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `customersCorporate[][personalDiscount]` | `double` | Персональная скидка |
| `customersCorporate[][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `customersCorporate[][discountCardNumber]` | `string` | Номер дисконтной карты |
| `customersCorporate[][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам корпоративного клиента (в базовой валюте) |
| `customersCorporate[][marginSumm]` | `float` | LTV (в базовой валюте) |
| `customersCorporate[][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `customersCorporate[][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `customersCorporate[][ordersCount]` | `integer` | Количество заказов |
| `customersCorporate[][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `customersCorporate[][mainCustomerContact]` | `object (CustomerContact)` | Основное контактное лицо |
| `customersCorporate[][mainCustomerContact][id]` | `integer` | ID контакта |
| `customersCorporate[][mainCustomerContact][customer]` | `object (SerializedRelationAbstractCustomer)` | Клиент |
| `customersCorporate[][mainCustomerContact][customer][id]` | `integer` | Внутренний ID клиента |
| `customersCorporate[][mainCustomerContact][customer][externalId]` | `string` | Внешний ID клиента |
| `customersCorporate[][mainCustomerContact][customer][browserId]` | `string` | Идентификатор устройства в Collector |
| `customersCorporate[][mainCustomerContact][companies][]` | `array of objects (CustomerContactCompany)` | Компания контактного лица |
| `customersCorporate[][mainCustomerContact][companies][][id]` | `integer` | ID компании |
| `customersCorporate[][mainCustomerContact][companies][][company]` | `object (EntityWithExternalIdNameOutput)` | Компания |
| `customersCorporate[][mainCustomerContact][companies][][company][id]` | `integer` | ID  |
| `customersCorporate[][mainCustomerContact][companies][][company][externalId]` | `string` | Внешний ID |
| `customersCorporate[][mainCustomerContact][companies][][company][name]` | `string` | Название |
| `customersCorporate[][mainCompany]` | `object (EntityWithExternalIdNameOutput)` | Основная компания |
| `customersCorporate[][mainCompany][id]` | `integer` | ID  |
| `customersCorporate[][mainCompany][externalId]` | `string` | Внешний ID |
| `customersCorporate[][mainCompany][name]` | `string` | Название |
| `customersCorporate[][nickName]` | `string` | Наименование |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/combine

Объединение корпоративных клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Позволяет объединить клиентов.

При конфликте полей сохранятся только данные клиента `resultCustomer`, данные прочих клиентов по конкретному полю будут удалены. Все адреса, компании и контактные лица будут добавлены клиенту `resultCustomer`. Клиенты из параметра `customers` будут безвозвратно удалены.

В ходе объединения клиентов будут объединены связанные с ними данные.

Одновременно можно объединять до 50 клиентов.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customers` | `array of objects (SerializedCustomerReference)` |     |     |
| `customers[id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID клиента |
| `resultCustomer` | `object (SerializedCustomerReference)` | `{not blank}` | Клиент, в которого произойдет объединение |
| `resultCustomer[id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/create

Создание корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Метод создает клиента и возвращает внутренний ID созданного клиента.

Если не указывать `customerCorporate[createdAt]`, то будет использовано текущее время в качестве даты/времени регистрации клиента.

`externalId` должен быть уникальным для клиентов и корпоративных клиентов в рамках одного магазина. Для разделения клиентов разных типов можно использовать префикс (например `i123234` для клиента и `l123234` корпоративного клиента)

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

Поля `customerCorporate[personalDiscount]` и `customerCorporate[discountCardNumber]` принимаются, если они включены в настройках.

В поле `customerCorporate[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Адреса клиента `customerCorporate[addresses]` можно указывать либо в строковом виде в поле `customerCorporate[addresses][][text]`, либо в подробном виде, заполняя все поля кроме `customerCorporate[addresses][][text]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customerCorporate` | `object (SerializedCustomerCorporate)` |     |     |
| `customerCorporate[externalId]` | `string` |     | Внешний ID корпоративного клиента |
| `customerCorporate[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Создан |
| `customerCorporate[vip]` | `boolean` |     | Важный клиент |
| `customerCorporate[bad]` | `boolean` |     | Плохой клиент |
| `customerCorporate[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customerCorporate[personalDiscount]` | `double` |     | Персональная скидка |
| `customerCorporate[discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customerCorporate[nickName]` | `string` |     | Наименование |
| `customerCorporate[managerId]` | `integer` |     | Менеджер корпоративного клиента |
| `customerCorporate[customerContacts][]` | `array of objects (SerializedCustomerContact)` |     | Контактные лица |
| `customerCorporate[customerContacts][][isMain]` | `boolean` |     | Контактное лицо является основным для клиента |
| `customerCorporate[customerContacts][][customer]` | `object (SerializedRelationAbstractCustomer)` |     | Клиент |
| `customerCorporate[customerContacts][][customer][id]` | `integer` |     | Внутренний ID клиента |
| `customerCorporate[customerContacts][][customer][externalId]` | `string` |     | Внешний ID клиента |
| `customerCorporate[customerContacts][][customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `customerCorporate[customerContacts][][customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `customerCorporate[customerContacts][][companies][]` | `array of objects (SerializedCustomerContactCompany)` |     | Компании контактного лица |
| `customerCorporate[customerContacts][][companies][][company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `customerCorporate[customerContacts][][companies][][company][id]` | `integer` |     | ID  |
| `customerCorporate[customerContacts][][companies][][company][externalId]` | `string` |     | Внешний ID |
| `customerCorporate[companies][]` | `array of objects (SerializedCompany)` |     | Компании |
| `customerCorporate[companies][][isMain]` | `boolean` |     | Компания является основной для клиента |
| `customerCorporate[companies][][externalId]` | `string` |     | Внешний ID компании |
| `customerCorporate[companies][][active]` | `boolean` |     | Активность |
| `customerCorporate[companies][][name]` | `string` |     | Наименование |
| `customerCorporate[companies][][brand]` | `string` |     | Бренд |
| `customerCorporate[companies][][site]` | `string` |     | Сайт компании |
| `customerCorporate[companies][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания |
| `customerCorporate[companies][][contragent]` | `object (SerializedCompanyContragent)` |     | Реквизиты |
| `customerCorporate[companies][][contragent][contragentType]` | `string` |     | Тип контрагента |
| `customerCorporate[companies][][contragent][legalName]` | `string` |     | Полное наименование |
| `customerCorporate[companies][][contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `customerCorporate[companies][][contragent][INN]` | `string` |     | ИНН |
| `customerCorporate[companies][][contragent][OKPO]` | `string` |     | ОКПО |
| `customerCorporate[companies][][contragent][KPP]` | `string` |     | КПП |
| `customerCorporate[companies][][contragent][OGRN]` | `string` |     | ОГРН |
| `customerCorporate[companies][][contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `customerCorporate[companies][][contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `customerCorporate[companies][][contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `customerCorporate[companies][][contragent][BIK]` | `string` |     | БИК |
| `customerCorporate[companies][][contragent][bank]` | `string` |     | Банк |
| `customerCorporate[companies][][contragent][bankAddress]` | `string` |     | Адрес банка |
| `customerCorporate[companies][][contragent][corrAccount]` | `string` |     | Корр. счёт |
| `customerCorporate[companies][][contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `customerCorporate[companies][][customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customerCorporate[companies][][address]` | `object (EntityWithExternalIdInput)` |     | Адрес |
| `customerCorporate[companies][][address][id]` | `integer` |     | ID  |
| `customerCorporate[companies][][address][externalId]` | `string` |     | Внешний ID |
| `customerCorporate[addresses][]` | `array of objects (CustomerAddress)` |     | Адреса корпоративного клиента |
| `customerCorporate[addresses][][id]` | `integer` |     | ID адреса |
| `customerCorporate[addresses][][index]` | `string` |     | Индекс |
| `customerCorporate[addresses][][countryIso]` | `string` |     | ISO код страны |
| `customerCorporate[addresses][][region]` | `string` |     | Регион |
| `customerCorporate[addresses][][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `customerCorporate[addresses][][city]` | `string` |     | Город |
| `customerCorporate[addresses][][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `customerCorporate[addresses][][cityType]` | `string` |     | Тип населенного пункта |
| `customerCorporate[addresses][][street]` | `string` |     | Улица |
| `customerCorporate[addresses][][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `customerCorporate[addresses][][streetType]` | `string` |     | Тип улицы |
| `customerCorporate[addresses][][building]` | `string` |     | Дом |
| `customerCorporate[addresses][][flat]` | `string` |     | Номер квартиры/офиса |
| `customerCorporate[addresses][][floor]` | `integer` |     | Этаж |
| `customerCorporate[addresses][][block]` | `integer` |     | Подъезд |
| `customerCorporate[addresses][][house]` | `string` |     | Строение |
| `customerCorporate[addresses][][housing]` | `string` |     | Корпус |
| `customerCorporate[addresses][][metro]` | `string` |     | Метро |
| `customerCorporate[addresses][][notes]` | `string` |     | Примечания к адресу |
| `customerCorporate[addresses][][text]` | `string` |     | Адрес в текстовом виде |
| `customerCorporate[addresses][][externalId]` | `string` |     | Внешний ID |
| `customerCorporate[addresses][][name]` | `string` |     | Наменование адреса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного клиента |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании клиента</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/fix-external-ids

Массовая запись внешних ID корпоративных клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Данный метод полезен в случае обратной синхронизации клиентов, которые исходно оформлены в системе.

Магазин запрашивает клиентов, созданных в системе, и создает их в своей базе. При создании клиентов в магазине формируются собственные ID клиентов (`externalId` клиентов в нотации системы). Сразу после создания клиентов интернет-магазин вызывает метод `/api/v*/customers-corporate/fix-external-ids`, сохраняя в системе собственные ID клиентов.

`externalId` должен быть уникальным для клиентов и корпоративных клиентов в рамках одного магазина. Для разделения клиентов разных типов можно использовать строковый префикс (например: `i123234` для клиента и `l123234` корпоративного клиента)

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `customersCorporate[]` | `array of objects (FixExternalRow)` |     | Идентификаторы загруженных объектов |
| `customersCorporate[][id]` | `digit` | `{not blank}{range: {>=1, <=2147483647}}}` | Внутренний ID |
| `customersCorporate[][externalId]` | `string` | `{not blank}` | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers-corporate/history

Получение истории изменения корпоративных клиентов

Для доступа к методу необходимо разрешение `customer_read`.

Возвращает изменения в клиентах, произведенные в указанный диапазон дат (используя фильтры `filter[startDate]` и `filter[endDate]`), либо инкрементальные изменения (используя `filter[sinceId]`). При реализации постоянной трансляции изменений во внешнюю систему ​**рекомендуется**​ использовать подход с забором инкрементальных изменений через `filter[sinceId]` передавая `id` последней полученной записи истории.

Для записей создания и удаления клиента возвращается полный набор полей в контексте `customer`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке. Для постраничного перебора записей истории ​**необходимо**​ использовать `filter[sinceId]`. Параметр `page` использовать не рекомендуется.

Более подробно о работе с историей вы можете ознакомиться в [отдельной статье документации](https://docs.retailcrm.ru/Developers/API/APIFeatures/WorkingHistoryAPI).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerHistoryFilterV4Type)` |     |     |
| `filter[customerId]` | `integer` | `{range: {>=0, <=4294967295}}` | ID клиента |
| `filter[sinceId]` | `integer` | `{range: {>=0, <=4294967295}}` | Начиная с ID истории клиентов |
| `filter[customerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID клиента |
| `filter[startDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (от) |
| `filter[endDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `generatedAt` | `DateTime` | Время формирования ответа |
| `history[]` | `array of objects (CustomerCorporateHistory)` | Изменение корпоративного клиента |
| `history[][id]` | `integer` | Внутренний идентификатор записи в истории |
| `history[][createdAt]` | `DateTime` | Дата внесения изменения |
| `history[][created]` | `boolean` | Признак создания сущности |
| `history[][deleted]` | `boolean` | Признак удаления сущности |
| `history[][source]` | `string` | Источник изменения |
| `history[][user]` | `object (User)` | Пользователь |
| `history[][user][id]` | `integer` | ID пользователя |
| `history[][field]` | `string` | Имя изменившегося поля |
| `history[][oldValue]` | `custom handler result for (mixed)` | Старое значение свойства |
| `history[][newValue]` | `custom handler result for (mixed)` | Новое значение свойства |
| `history[][apiKey]` | `object (ApiKey)` | Информация о ключе api, использовавшемся для этого изменения |
| `history[][apiKey][current]` | `boolean` | Изменение было сделано с помощью ключа, используемого в данный момент |
| `history[][apiKey][id]` | `integer` | ID API-ключа |
| `history[][customer]` | `object (CustomerCorporate)` | Корпоративный клиент |
| `history[][customer][id]` | `integer` | ID корпоративного клиента |
| `history[][customer][externalId]` | `string` | Внешний ID корпоративного клиента |
| `history[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `history[][address]` | `object (CustomerAddressWithIsMain)` | Адрес клиента |
| `history[][address][id]` | `integer` | ID адреса клиента |
| `history[][address][externalId]` | `string` | Внешний ID |
| `history[][address][name]` | `string` | Наменование адреса |
| `history[][address][isMain]` | `boolean` | Адрес клиента является основным |
| `history[][combinedTo]` | `object (CustomerCorporate)` | Информация о клиенте, который получился после объединения с текущим клиентом |
| `history[][subscription]` | `object (Subscription)` | Категория подписки |
| `history[][subscription][channel]` | `string` | Канал |
| `history[][subscription][name]` | `string` | Название |
| `history[][subscription][code]` | `string` | Символьный код |
| `history[][customerContact]` | `object (CustomerContact)` | Контактное лицо |
| `history[][customerContact][id]` | `integer` | ID контакта |
| `history[][company]` | `object (Company)` | Компания |
| `history[][company][id]` | `integer` | ID компании |
| `history[][company][externalId]` | `string` | Внешний ID компании |
| `history[][company][name]` | `string` | Наименование |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers-corporate/notes

Получение заметок

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтре `filter[ids][]` передаётся массив внутренних идентификаторов заметок.

В фильтрах `filter[customerIds][]` и `filter[customerExternalIds][]` передаются массивы внутренних и внешних идентификаторов клиентов.

В фильтре `filter[managerIds][]` передаётся массив внутренних идентификаторов менеджеров.

Фильтр `filter[text]` позволяет получить заметки по частичному совпадению заданного текста.

Фильтры `filter[createdAtFrom]` и `filter[createdAtTo]` позволяют получить заметки созданные в заданном интервале времени.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerNoteFilter)` |     |     |
| `filter[ids]` | `array of integers` |     | ID заметок |
| `filter[customerIds]` | `array of integers` |     | Внутренние ID клиентов |
| `filter[customerExternalIds]` | `array of strings` |     | Внешние ID клиентов |
| `filter[managerIds]` | `array of integers` |     | ID менеджеров |
| `filter[text]` | `string` |     | Текст заметки |
| `filter[createdAtFrom]` | `string` |     | Дата/время создания (от) |
| `filter[createdAtTo]` | `string` |     | Дата/время создания (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `notes[]` | `array of objects (CustomerNote)` | Заметка |
| `notes[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `notes[][customer][site]` | `string` | Символьный код магазина |
| `notes[][customer][id]` | `integer` | Внутренний ID клиента |
| `notes[][customer][externalId]` | `string` | Внешний ID клиента |
| `notes[][customer][type]` | `string` | Тип клиента |
| `notes[][managerId]` | `integer` | ID менеджера |
| `notes[][id]` | `integer` | ID заметки |
| `notes[][text]` | `string` | Текст заметки |
| `notes[][createdAt]` | `DateTime` | Дата/время создания |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/notes/create

Создание заметки

Для доступа к методу необходимо разрешение `customer_write`.

Метод создает заметку и в случае успеха возвращает её внутренний ID.

В поле `note[managerId]` можно указать внутренний ID менеджера к которому необходимо привязать создаваемую заметку.

В поле `note[text]` **необходимо** задать содержание заметки. Длина текста должна быть не менее 1 символа и не более 2000 символов. Допускается использовать HTML теги (`p`, `a`, `ul`, `ol`, `strong`, `em`, `blockquote`). При использовании в тексте HTML разметки важно соблюдать семантику и следить за наличием закрывающих тегов, иначе система выдаст ошибку.

В поле `note[customer]` **необходимо** задать клиента к которому будет привязана создаваемая заметка. Сделать это можно используя внутренний `note[customer][id]` либо внешний `note[customer][externalId]` ID клиента.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `note` | `object (SerializedCustomerNote)` |     | Заметка |
| `note[managerId]` | `integer` |     | Внутренний ID менеджера |
| `note[text]` | `string` |     | Текст заметки |
| `note[customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `note[customer][id]` | `integer` |     | Внутренний ID клиента |
| `note[customer][externalId]` | `string` |     | Внешний ID клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданной заметки |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании заметки</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/notes/{id}/delete

Удаление заметки

Для доступа к методу необходимо разрешение `customer_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID заметки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка успешно удалена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заметка не найдена</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customers-corporate/upload

Пакетная загрузка корпоративных клиентов

Для доступа к методу необходимо разрешение `customer_write`.

Метод позволяет загружать пакетно до 50 клиентов.

Подробную информацию по формату данных см. в описании метода `/api/v*/customers-corporate/create`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина, к которому относятся загружаемые клиенты |
| `customersCorporate[]` | `array of objects (SerializedCustomerCorporate)` |     |     |
| `customersCorporate[][externalId]` | `string` |     | Внешний ID корпоративного клиента |
| `customersCorporate[][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Создан |
| `customersCorporate[][vip]` | `boolean` |     | Важный клиент |
| `customersCorporate[][bad]` | `boolean` |     | Плохой клиент |
| `customersCorporate[][customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customersCorporate[][personalDiscount]` | `double` |     | Персональная скидка |
| `customersCorporate[][discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customersCorporate[][nickName]` | `string` |     | Наименование |
| `customersCorporate[][managerId]` | `integer` |     | Менеджер корпоративного клиента |
| `customersCorporate[][customerContacts][]` | `array of objects (SerializedCustomerContact)` |     | Контактные лица |
| `customersCorporate[][customerContacts][][isMain]` | `boolean` |     | Контактное лицо является основным для клиента |
| `customersCorporate[][customerContacts][][customer]` | `object (SerializedRelationAbstractCustomer)` |     | Клиент |
| `customersCorporate[][customerContacts][][customer][id]` | `integer` |     | Внутренний ID клиента |
| `customersCorporate[][customerContacts][][customer][externalId]` | `string` |     | Внешний ID клиента |
| `customersCorporate[][customerContacts][][customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `customersCorporate[][customerContacts][][customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `customersCorporate[][customerContacts][][companies][]` | `array of objects (SerializedCustomerContactCompany)` |     | Компании контактного лица |
| `customersCorporate[][customerContacts][][companies][][company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `customersCorporate[][customerContacts][][companies][][company][id]` | `integer` |     | ID  |
| `customersCorporate[][customerContacts][][companies][][company][externalId]` | `string` |     | Внешний ID |
| `customersCorporate[][companies][]` | `array of objects (SerializedCompany)` |     | Компании |
| `customersCorporate[][companies][][isMain]` | `boolean` |     | Компания является основной для клиента |
| `customersCorporate[][companies][][externalId]` | `string` |     | Внешний ID компании |
| `customersCorporate[][companies][][active]` | `boolean` |     | Активность |
| `customersCorporate[][companies][][name]` | `string` |     | Наименование |
| `customersCorporate[][companies][][brand]` | `string` |     | Бренд |
| `customersCorporate[][companies][][site]` | `string` |     | Сайт компании |
| `customersCorporate[][companies][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания |
| `customersCorporate[][companies][][contragent]` | `object (SerializedCompanyContragent)` |     | Реквизиты |
| `customersCorporate[][companies][][contragent][contragentType]` | `string` |     | Тип контрагента |
| `customersCorporate[][companies][][contragent][legalName]` | `string` |     | Полное наименование |
| `customersCorporate[][companies][][contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `customersCorporate[][companies][][contragent][INN]` | `string` |     | ИНН |
| `customersCorporate[][companies][][contragent][OKPO]` | `string` |     | ОКПО |
| `customersCorporate[][companies][][contragent][KPP]` | `string` |     | КПП |
| `customersCorporate[][companies][][contragent][OGRN]` | `string` |     | ОГРН |
| `customersCorporate[][companies][][contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `customersCorporate[][companies][][contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `customersCorporate[][companies][][contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `customersCorporate[][companies][][contragent][BIK]` | `string` |     | БИК |
| `customersCorporate[][companies][][contragent][bank]` | `string` |     | Банк |
| `customersCorporate[][companies][][contragent][bankAddress]` | `string` |     | Адрес банка |
| `customersCorporate[][companies][][contragent][corrAccount]` | `string` |     | Корр. счёт |
| `customersCorporate[][companies][][contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `customersCorporate[][companies][][customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customersCorporate[][companies][][address]` | `object (EntityWithExternalIdInput)` |     | Адрес |
| `customersCorporate[][companies][][address][id]` | `integer` |     | ID  |
| `customersCorporate[][companies][][address][externalId]` | `string` |     | Внешний ID |
| `customersCorporate[][addresses][]` | `array of objects (CustomerAddress)` |     | Адреса корпоративного клиента |
| `customersCorporate[][addresses][][id]` | `integer` |     | ID адреса |
| `customersCorporate[][addresses][][index]` | `string` |     | Индекс |
| `customersCorporate[][addresses][][countryIso]` | `string` |     | ISO код страны |
| `customersCorporate[][addresses][][region]` | `string` |     | Регион |
| `customersCorporate[][addresses][][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `customersCorporate[][addresses][][city]` | `string` |     | Город |
| `customersCorporate[][addresses][][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `customersCorporate[][addresses][][cityType]` | `string` |     | Тип населенного пункта |
| `customersCorporate[][addresses][][street]` | `string` |     | Улица |
| `customersCorporate[][addresses][][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `customersCorporate[][addresses][][streetType]` | `string` |     | Тип улицы |
| `customersCorporate[][addresses][][building]` | `string` |     | Дом |
| `customersCorporate[][addresses][][flat]` | `string` |     | Номер квартиры/офиса |
| `customersCorporate[][addresses][][floor]` | `integer` |     | Этаж |
| `customersCorporate[][addresses][][block]` | `integer` |     | Подъезд |
| `customersCorporate[][addresses][][house]` | `string` |     | Строение |
| `customersCorporate[][addresses][][housing]` | `string` |     | Корпус |
| `customersCorporate[][addresses][][metro]` | `string` |     | Метро |
| `customersCorporate[][addresses][][notes]` | `string` |     | Примечания к адресу |
| `customersCorporate[][addresses][][text]` | `string` |     | Адрес в текстовом виде |
| `customersCorporate[][addresses][][externalId]` | `string` |     | Внешний ID |
| `customersCorporate[][addresses][][name]` | `string` |     | Наменование адреса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `uploadedCustomers[]` | `array of objects (FixExternalRow)` | Идентификаторы загруженных объектов |
| `uploadedCustomers[][id]` | `integer` | Внутренний ID |
| `uploadedCustomers[][externalId]` | `string` | Внешний ID |
| `failedCustomers[]` | `array of objects (EntityWithExternalId)` | Идентификаторы незагруженных объектов |
| `failedCustomers[][externalId]` | `string` | Внешний ID (при наличии) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Все клиенты успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Не передан массив клиентов, либо массив слишком большой</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть клиентов не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers-corporate/{externalId}

Получение информации о корпоративном клиенте

Для доступа к методу необходимо разрешение `customer_read`.

Метод возвращает полную информацию по клиенту. Можно обращаться к клиенту как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Пустые поля без значений не возвращаются.

В поле `managerId` возвращается внутренний ID сущности в системе.

Поля `personalDiscount`, `cumulativeDiscount` и `discountCardNumber` возвращаются, если они включены в настройках.

В поле `customFields` возвращается массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalId` | `string` |     | ID клиента |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `customerCorporate` | `object (CustomerCorporate)` | Корпоративный клиент |
| `customerCorporate[type]` | `string` | Тип клиента |
| `customerCorporate[id]` | `integer` | ID корпоративного клиента |
| `customerCorporate[externalId]` | `string` | Внешний ID корпоративного клиента |
| `customerCorporate[mainAddress]` | `object (EntityWithExternalIdNameOutput)` | Основной адрес корпоративного клиента |
| `customerCorporate[mainAddress][id]` | `integer` | ID  |
| `customerCorporate[mainAddress][externalId]` | `string` | Внешний ID |
| `customerCorporate[mainAddress][name]` | `string` | Название |
| `customerCorporate[createdAt]` | `DateTime` | Создан |
| `customerCorporate[managerId]` | `integer` | Менеджер корпоративного клиента |
| `customerCorporate[vip]` | `boolean` | Важный клиент |
| `customerCorporate[bad]` | `boolean` | Плохой клиент |
| `customerCorporate[site]` | `string` | Магазин, с которого пришел клиент |
| `customerCorporate[tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `customerCorporate[tags][][color]` | `string` |     |
| `customerCorporate[tags][][name]` | `string` |     |
| `customerCorporate[tags][][colorCode]` | `string` |     |
| `customerCorporate[tags][][attached]` | `boolean` |     |
| `customerCorporate[firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `customerCorporate[lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `customerCorporate[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `customerCorporate[personalDiscount]` | `double` | Персональная скидка |
| `customerCorporate[cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `customerCorporate[discountCardNumber]` | `string` | Номер дисконтной карты |
| `customerCorporate[avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам корпоративного клиента (в базовой валюте) |
| `customerCorporate[marginSumm]` | `float` | LTV (в базовой валюте) |
| `customerCorporate[totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `customerCorporate[averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `customerCorporate[ordersCount]` | `integer` | Количество заказов |
| `customerCorporate[costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `customerCorporate[mainCustomerContact]` | `object (CustomerContact)` | Основное контактное лицо |
| `customerCorporate[mainCustomerContact][id]` | `integer` | ID контакта |
| `customerCorporate[mainCustomerContact][customer]` | `object (SerializedRelationAbstractCustomer)` | Клиент |
| `customerCorporate[mainCustomerContact][customer][id]` | `integer` | Внутренний ID клиента |
| `customerCorporate[mainCustomerContact][customer][externalId]` | `string` | Внешний ID клиента |
| `customerCorporate[mainCustomerContact][customer][browserId]` | `string` | Идентификатор устройства в Collector |
| `customerCorporate[mainCustomerContact][companies][]` | `array of objects (CustomerContactCompany)` | Компания контактного лица |
| `customerCorporate[mainCustomerContact][companies][][id]` | `integer` | ID компании |
| `customerCorporate[mainCustomerContact][companies][][company]` | `object (EntityWithExternalIdNameOutput)` | Компания |
| `customerCorporate[mainCustomerContact][companies][][company][id]` | `integer` | ID  |
| `customerCorporate[mainCustomerContact][companies][][company][externalId]` | `string` | Внешний ID |
| `customerCorporate[mainCustomerContact][companies][][company][name]` | `string` | Название |
| `customerCorporate[mainCompany]` | `object (EntityWithExternalIdNameOutput)` | Основная компания |
| `customerCorporate[mainCompany][id]` | `integer` | ID  |
| `customerCorporate[mainCompany][externalId]` | `string` | Внешний ID |
| `customerCorporate[mainCompany][name]` | `string` | Название |
| `customerCorporate[nickName]` | `string` | Наименование |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент не найден</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customers-corporate/{externalId}/addresses

Список адресов корпоративного клиента

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Можно обращаться к адресам клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Если адрес клиента указывался в строковом виде, то он будет возвращен в `address[text]`. Если адрес указывался в детальном виде, то будут возвращены все заполненные поля доставки, а в `address[text]` будет находиться автоматически сформированное текстовое представление адреса.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerAddressFilterData)` |     |     |
| `filter[ids]` | `string` |     |     |
| `filter[name]` | `string` | `{length: {max: 255}}` |     |
| `filter[city]` | `string` | `{length: {max: 255}}` |     |
| `filter[region]` | `string` | `{length: {max: 255}}` |     |
| `externalId` | `string` |     | ID клиента |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `addresses[]` | `array of objects (CustomerAddress)` | Адрес клиента |
| `addresses[][id]` | `integer` | ID адреса |
| `addresses[][index]` | `string` | Индекс |
| `addresses[][countryIso]` | `string` | ISO код страны |
| `addresses[][region]` | `string` | Регион |
| `addresses[][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `addresses[][city]` | `string` | Город |
| `addresses[][cityId]` | `integer` | Идентификатор города в Geohelper |
| `addresses[][cityType]` | `string` | Тип населенного пункта |
| `addresses[][street]` | `string` | Улица |
| `addresses[][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `addresses[][streetType]` | `string` | Тип улицы |
| `addresses[][building]` | `string` | Дом |
| `addresses[][flat]` | `string` | Номер квартиры/офиса |
| `addresses[][floor]` | `integer` | Этаж |
| `addresses[][block]` | `integer` | Подъезд |
| `addresses[][house]` | `string` | Строение |
| `addresses[][housing]` | `string` | Корпус |
| `addresses[][metro]` | `string` | Метро |
| `addresses[][notes]` | `string` | Примечания к адресу |
| `addresses[][text]` | `string` | Адрес в текстовом виде |
| `addresses[][isMain]` | `boolean` | Адрес является основным для клиента |
| `addresses[][externalId]` | `string` | Внешний ID |
| `addresses[][name]` | `string` | Наменование адреса |

### POST /api/v5/customers-corporate/{externalId}/addresses/create

Создание адреса для корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Адрес клиента `address` можно указывать либо в строковом виде в поле `address[text]`, либо в подробном виде, заполняя все поля кроме `address[text]`.

Для клиента можно создать не более 100 адресов.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `address` | `object (SerializedCustomerAddress)` |     |     |
| `address[index]` | `string` |     | Индекс |
| `address[countryIso]` | `string` |     | ISO код страны |
| `address[region]` | `string` |     | Регион |
| `address[regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `address[city]` | `string` |     | Город |
| `address[cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `address[cityType]` | `string` |     | Тип населенного пункта |
| `address[street]` | `string` |     | Улица |
| `address[streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `address[streetType]` | `string` |     | Тип улицы |
| `address[building]` | `string` |     | Дом |
| `address[flat]` | `string` |     | Номер квартиры/офиса |
| `address[floor]` | `integer` |     | Этаж |
| `address[block]` | `integer` |     | Подъезд |
| `address[house]` | `string` |     | Строение |
| `address[housing]` | `string` |     | Корпус |
| `address[metro]` | `string` |     | Метро |
| `address[notes]` | `string` |     | Примечания к адресу |
| `address[text]` | `string` |     | Адрес в текстовом виде |
| `address[isMain]` | `boolean` |     | Адрес является основным для клиента |
| `address[externalId]` | `string` |     | Внешний ID |
| `address[name]` | `string` |     | Наменование адреса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### POST /api/v5/customers-corporate/{externalId}/addresses/{entityExternalId}/edit

Редактирование адреса корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Можно обращаться к адресам клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Редактировать адрес клиента (`entityExternalId`) можно как по внешнему ID адреса (`entityBy=externalId`), так и по внутреннему ID (`entityBy=id`).

Адрес клиента `address` можно указывать либо в строковом виде в поле `address[text]`, либо в подробном виде, заполняя все поля кроме `address[text]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId, entityBy=externalId) |
| `entityBy` | `string` |     | Указывается, что передается в параметре entityExternalId: внутренний (entityBy=id) или внешний (entityBy=externalId) ID. По умолчанию externalId. |
| `address` | `object (SerializedCustomerAddress)` |     |     |
| `address[index]` | `string` |     | Индекс |
| `address[countryIso]` | `string` |     | ISO код страны |
| `address[region]` | `string` |     | Регион |
| `address[regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `address[city]` | `string` |     | Город |
| `address[cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `address[cityType]` | `string` |     | Тип населенного пункта |
| `address[street]` | `string` |     | Улица |
| `address[streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `address[streetType]` | `string` |     | Тип улицы |
| `address[building]` | `string` |     | Дом |
| `address[flat]` | `string` |     | Номер квартиры/офиса |
| `address[floor]` | `integer` |     | Этаж |
| `address[block]` | `integer` |     | Подъезд |
| `address[house]` | `string` |     | Строение |
| `address[housing]` | `string` |     | Корпус |
| `address[metro]` | `string` |     | Метро |
| `address[notes]` | `string` |     | Примечания к адресу |
| `address[text]` | `string` |     | Адрес в текстовом виде |
| `address[isMain]` | `boolean` |     | Адрес является основным для клиента |
| `address[externalId]` | `string` |     | Внешний ID |
| `address[name]` | `string` |     | Наменование адреса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### GET /api/v5/customers-corporate/{externalId}/companies

Список компаний корпоративного клиента

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Можно обращаться к компаниям клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Если адрес клиента указывался в строковом виде, то он будет возвращен в `address[text]`. Если адрес указывался в детальном виде, то будут возвращены все заполненные поля доставки, а в `address[text]` будет находиться автоматически сформированное текстовое представление адреса.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CompanyFilterData)` |     |     |
| `filter[ids]` | `string` |     | Массив ID компаниий |
| `externalId` | `string` |     | ID клиента |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `companies[]` | `array of objects (Company)` | Компания |
| `companies[][isMain]` | `boolean` | Компания является основной для клиента |
| `companies[][id]` | `integer` | ID компании |
| `companies[][externalId]` | `string` | Внешний ID компании |
| `companies[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `companies[][customer][site]` | `string` | Символьный код магазина |
| `companies[][customer][id]` | `integer` | Внутренний ID клиента |
| `companies[][customer][externalId]` | `string` | Внешний ID клиента |
| `companies[][customer][type]` | `string` | Тип клиента |
| `companies[][active]` | `boolean` | Активность |
| `companies[][name]` | `string` | Наименование |
| `companies[][brand]` | `string` | Бренд |
| `companies[][site]` | `string` | Сайт компании |
| `companies[][createdAt]` | `DateTime` | Дата создания |
| `companies[][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `companies[][contragent][contragentType]` | `string` | Тип контрагента |
| `companies[][contragent][legalName]` | `string` | Полное наименование |
| `companies[][contragent][legalAddress]` | `string` | Адрес регистрации |
| `companies[][contragent][INN]` | `string` | ИНН |
| `companies[][contragent][OKPO]` | `string` | ОКПО |
| `companies[][contragent][KPP]` | `string` | КПП |
| `companies[][contragent][OGRN]` | `string` | ОГРН |
| `companies[][contragent][OGRNIP]` | `string` | ОГРНИП |
| `companies[][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `companies[][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `companies[][contragent][BIK]` | `string` | БИК |
| `companies[][contragent][bank]` | `string` | Банк |
| `companies[][contragent][bankAddress]` | `string` | Адрес банка |
| `companies[][contragent][corrAccount]` | `string` | Корр. счёт |
| `companies[][contragent][bankAccount]` | `string` | Расчётный счёт |
| `companies[][address]` | `object (CustomerAddress)` | Адрес |
| `companies[][address][id]` | `integer` | ID адреса |
| `companies[][address][index]` | `string` | Индекс |
| `companies[][address][countryIso]` | `string` | ISO код страны |
| `companies[][address][region]` | `string` | Регион |
| `companies[][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `companies[][address][city]` | `string` | Город |
| `companies[][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `companies[][address][cityType]` | `string` | Тип населенного пункта |
| `companies[][address][street]` | `string` | Улица |
| `companies[][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `companies[][address][streetType]` | `string` | Тип улицы |
| `companies[][address][building]` | `string` | Дом |
| `companies[][address][flat]` | `string` | Номер квартиры/офиса |
| `companies[][address][floor]` | `integer` | Этаж |
| `companies[][address][block]` | `integer` | Подъезд |
| `companies[][address][house]` | `string` | Строение |
| `companies[][address][housing]` | `string` | Корпус |
| `companies[][address][metro]` | `string` | Метро |
| `companies[][address][notes]` | `string` | Примечания к адресу |
| `companies[][address][text]` | `string` | Адрес в текстовом виде |
| `companies[][address][externalId]` | `string` | Внешний ID |
| `companies[][address][name]` | `string` | Наменование адреса |
| `companies[][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `companies[][marginSumm]` | `float` | LTV (в базовой валюте) |
| `companies[][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `companies[][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `companies[][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `companies[][ordersCount]` | `integer` | Количество заказов |
| `companies[][customFields]` | `array` | Ассоциативный массив пользовательских полей |

### POST /api/v5/customers-corporate/{externalId}/companies/create

Создание компании для корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

В поле `company[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `company` | `object (SerializedCompany)` |     |     |
| `company[isMain]` | `boolean` |     | Компания является основной для клиента |
| `company[externalId]` | `string` |     | Внешний ID компании |
| `company[active]` | `boolean` |     | Активность |
| `company[name]` | `string` |     | Наименование |
| `company[brand]` | `string` |     | Бренд |
| `company[site]` | `string` |     | Сайт компании |
| `company[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания |
| `company[contragent]` | `object (SerializedCompanyContragent)` |     | Реквизиты |
| `company[contragent][contragentType]` | `string` |     | Тип контрагента |
| `company[contragent][legalName]` | `string` |     | Полное наименование |
| `company[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `company[contragent][INN]` | `string` |     | ИНН |
| `company[contragent][OKPO]` | `string` |     | ОКПО |
| `company[contragent][KPP]` | `string` |     | КПП |
| `company[contragent][OGRN]` | `string` |     | ОГРН |
| `company[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `company[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `company[contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `company[contragent][BIK]` | `string` |     | БИК |
| `company[contragent][bank]` | `string` |     | Банк |
| `company[contragent][bankAddress]` | `string` |     | Адрес банка |
| `company[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `company[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `company[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `company[address]` | `object (EntityWithExternalIdInput)` |     | Адрес |
| `company[address][id]` | `integer` |     | ID  |
| `company[address][externalId]` | `string` |     | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### POST /api/v5/customers-corporate/{externalId}/companies/{entityExternalId}/edit

Редактирование компании корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Можно обращаться к компаниям клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Редактировать компанию клиента (`entityExternalId`) можно как по внешнему ID компании (`entityBy=externalId`), так и по внутреннему ID (`entityBy=id`).

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

В поле `company[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId, entityBy=externalId) |
| `entityBy` | `string` |     | Указывается, что передается в параметре entityExternalId: внутренний (entityBy=id) или внешний (entityBy=externalId) ID. По умолчанию externalId. |
| `company` | `object (SerializedCompany)` |     |     |
| `company[isMain]` | `boolean` |     | Компания является основной для клиента |
| `company[externalId]` | `string` |     | Внешний ID компании |
| `company[active]` | `boolean` |     | Активность |
| `company[name]` | `string` |     | Наименование |
| `company[brand]` | `string` |     | Бренд |
| `company[site]` | `string` |     | Сайт компании |
| `company[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания |
| `company[contragent]` | `object (SerializedCompanyContragent)` |     | Реквизиты |
| `company[contragent][contragentType]` | `string` |     | Тип контрагента |
| `company[contragent][legalName]` | `string` |     | Полное наименование |
| `company[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `company[contragent][INN]` | `string` |     | ИНН |
| `company[contragent][OKPO]` | `string` |     | ОКПО |
| `company[contragent][KPP]` | `string` |     | КПП |
| `company[contragent][OGRN]` | `string` |     | ОГРН |
| `company[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `company[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `company[contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `company[contragent][BIK]` | `string` |     | БИК |
| `company[contragent][bank]` | `string` |     | Банк |
| `company[contragent][bankAddress]` | `string` |     | Адрес банка |
| `company[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `company[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `company[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `company[address]` | `object (EntityWithExternalIdInput)` |     | Адрес |
| `company[address][id]` | `integer` |     | ID  |
| `company[address][externalId]` | `string` |     | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### GET /api/v5/customers-corporate/{externalId}/contacts

Список контактных лиц корпоративного клиента

Для доступа к методу необходимо разрешение `customer_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Можно обращаться к контактным лицам клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (CustomerContactFilterData)` |     |     |
| `filter[ids]` | `string` |     | Массив ID контактных лиц |
| `externalId` | `string` |     | ID клиента |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `contacts[]` | `array of objects (CustomerContact)` | Контактное лицо |
| `contacts[][isMain]` | `boolean` |     |
| `contacts[][id]` | `integer` | ID контакта |
| `contacts[][customer]` | `object (SerializedRelationAbstractCustomer)` | Клиент |
| `contacts[][customer][id]` | `integer` | Внутренний ID клиента |
| `contacts[][customer][externalId]` | `string` | Внешний ID клиента |
| `contacts[][customer][browserId]` | `string` | Идентификатор устройства в Collector |
| `contacts[][customer][site]` | `string` | Код магазина, необходим при передаче externalId |
| `contacts[][companies][]` | `array of objects (CustomerContactCompany)` | Компания контактного лица |
| `contacts[][companies][][id]` | `integer` | ID компании |
| `contacts[][companies][][company]` | `object (EntityWithExternalIdNameOutput)` | Компания |
| `contacts[][companies][][company][id]` | `integer` | ID  |
| `contacts[][companies][][company][externalId]` | `string` | Внешний ID |
| `contacts[][companies][][company][name]` | `string` | Название |

### POST /api/v5/customers-corporate/{externalId}/contacts/create

Создание связи корпоративного клиента с контактным лицом

Для доступа к методу необходимо разрешение `customer_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `contact` | `object (SerializedCustomerContact)` |     |     |
| `contact[isMain]` | `boolean` |     | Контактное лицо является основным для клиента |
| `contact[customer]` | `object (SerializedRelationAbstractCustomer)` |     | Клиент |
| `contact[customer][id]` | `integer` |     | Внутренний ID клиента |
| `contact[customer][externalId]` | `string` |     | Внешний ID клиента |
| `contact[customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `contact[customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `contact[companies][]` | `array of objects (SerializedCustomerContactCompany)` |     | Компании контактного лица |
| `contact[companies][][company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `contact[companies][][company][id]` | `integer` |     | ID  |
| `contact[companies][][company][externalId]` | `string` |     | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### POST /api/v5/customers-corporate/{externalId}/contacts/{entityExternalId}/edit

Редактирование связи корпоративного клиента с контактным лицом

Для доступа к методу необходимо разрешение `customer_write`.

Можно обращаться к контактным лицам клиента как по внешнему ID клиента (`by=externalId`), так и по внутреннему ID (`by=id`).

Редактировать контактное лицо клиента (`entityExternalId`) можно как по внешнему ID контактного лица (`entityBy=externalId`), так и по внутреннему ID (`entityBy=id`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId, entityBy=externalId) |
| `entityBy` | `string` |     | Указывается, что передается в параметре entityExternalId: внутренний (entityBy=id) или внешний (entityBy=externalId) ID. По умолчанию externalId. |
| `contact` | `object (SerializedCustomerContact)` |     |     |
| `contact[isMain]` | `boolean` |     | Контактное лицо является основным для клиента |
| `contact[companies][]` | `array of objects (SerializedCustomerContactCompany)` |     | Компании контактного лица |
| `contact[companies][][company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `contact[companies][][company][id]` | `integer` |     | ID  |
| `contact[companies][][company][externalId]` | `string` |     | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` |     |

### POST /api/v5/customers-corporate/{externalId}/edit

Редактирование корпоративного клиента

Для доступа к методу необходимо разрешение `customer_write`.

Метод позволяет вносить изменения в клиента.

В случае, если производится попытка отредактировать удаленного клиента, система возвращает в ответе `state=removed`.

Поля `customer[personalDiscount]` и `customer[discountCardNumber]` принимаются, если они включены в настройках.

В поле `customer[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения по externalId (by=externalId) |
| `customerCorporate` | `object (SerializedCustomerCorporate)` |     |     |
| `customerCorporate[externalId]` | `string` |     | Внешний ID корпоративного клиента |
| `customerCorporate[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Создан |
| `customerCorporate[vip]` | `boolean` |     | Важный клиент |
| `customerCorporate[bad]` | `boolean` |     | Плохой клиент |
| `customerCorporate[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `customerCorporate[personalDiscount]` | `double` |     | Персональная скидка |
| `customerCorporate[discountCardNumber]` | `string` |     | Номер дисконтной карты |
| `customerCorporate[nickName]` | `string` |     | Наименование |
| `customerCorporate[managerId]` | `integer` |     | Менеджер корпоративного клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID клиента |
| `state` | `string` | Состояние клиента (по умолчанию не возвращается) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Клиент успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении клиента</p></li></ul></td></tr></tbody></table>

## Действия клиента на сайте магазина

### POST /api/v5/customer-interaction/{site}/cart/clearОчистка текущей корзины клиента

### POST /api/v5/customer-interaction/{site}/cart/set

Создание или перезапись данных корзины

Для доступа к методу необходимо разрешение `customer_interaction_write`.

В параметре `{site}` можно передавать либо символьный код магазина либо ID магазина. По умолчанию будет использоваться символьный код. Для использования ID магазина также необходимо передать параметр `siteBy=id`.

Если у клиента есть корзина в магазине с пустым `clearedAt`, то запрос перезапишет её данные. Иначе будет создана новая корзина

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `cart` | `object (SerializedCart)` |     | Корзина |
| `cart[externalId]` | `string` |     | Внешний ID корзины |
| `cart[droppedAt]` | `DateTime` | `Y-m-d H:i:sP` | Дата/время, когда корзина стала брошенной |
| `cart[link]` | `string` |     | Ссылка |
| `cart[customer]` | `object (SerializedRelationAbstractCustomerWithGa)` |     |     |
| `cart[customer][id]` | `integer` |     | Внутренний ID клиента |
| `cart[customer][externalId]` | `string` |     | Внешний ID клиента |
| `cart[customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `cart[customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `cart[customer][gaClientId]` | `string` |     | Метка клиента Google Analytics |
| `cart[items][]` | `array of objects (SerializedCartItem)` |     | Товары в корзине |
| `cart[items][][quantity]` | `float` |     | Количество |
| `cart[items][][price]` | `float` |     | Цена (в валюте объекта) |
| `cart[items][][offer]` | `object (SerializedRelationOffer)` |     | Торговое предложение |
| `cart[items][][offer][id]` | `integer` |     | ID торгового предложения |
| `cart[items][][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `cart[items][][offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>siteBy</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре site: внутренний ID (siteBy=id) или код (siteBy=code) магазина. По умолчанию code.</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Корзина успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Корзина успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка создания корзины</p></li></ul></td></tr></tbody></table>

### GET /api/v5/customer-interaction/{site}/cart/{customerId}Получение текущей корзины клиента

### GET /api/v5/customer-interaction/{site}/favorites/{customerId}

Получение списка избранного для клиента

Для доступа к методу необходимо разрешение `customer_interaction_read`.

В параметре `{site}` можно передавать либо символьный код магазина либо ID магазина. По умолчанию будет использоваться символьный код. Для использования ID магазина также необходимо передать параметр `siteBy=id`.

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре customerId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>siteBy</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре site: внутренний ID (siteBy=id) или код (siteBy=code) магазина. По умолчанию code.</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `favorites[]` | `array of objects (CustomerOffer)` |     |
| `favorites[][offer]` | `object (Offer)` | Торговое предложение (SKU) |
| `favorites[][offer][displayName]` | `string` | Название SKU |
| `favorites[][offer][id]` | `integer` | ID торгового предложения |
| `favorites[][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `favorites[][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `favorites[][offer][name]` | `string` | Название |
| `favorites[][offer][article]` | `string` | Артикул |
| `favorites[][offer][vatRate]` | `string` | Ставка НДС |
| `favorites[][offer][properties][]` | `array` | Свойства SKU |
| `favorites[][offer][unit]` | `object (Unit)` | Единица измерения |
| `favorites[][offer][unit][code]` | `string` | Символьный код |
| `favorites[][offer][unit][name]` | `string` | Название |
| `favorites[][offer][unit][sym]` | `string` | Краткое обозначение |
| `favorites[][offer][barcode]` | `string` | Штрих-код |
| `favorites[][createdAt]` | `DateTime` | Дата добавления в список избранного |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Список избранного не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customer-interaction/{site}/favorites/{customerId}/add

Добавление товарного предложения в список избранного клиента

Для доступа к методу необходимо разрешение `customer_interaction_write`.

В параметре `{site}` можно передавать либо символьный код магазина либо ID магазина. По умолчанию будет использоваться символьный код. Для использования ID магазина также необходимо передать параметр `siteBy=id`.

Если товарное предложение уже находится в списке избранного, возвращается успешный ответ `success=true`

Максимальное количество товарных предложений в списке избранного - 1000. При добавлении нового товарного предложения сверх указанного лимита возвращается неуспешный ответ `success=false`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `favorite` | `object (SerializedFavorite)` |     |     |
| `favorite[offer]` | `object (SerializedRelationOffer)` |     | Торговое предложение |
| `favorite[offer][id]` | `integer` |     | ID торгового предложения |
| `favorite[offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `favorite[offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре customerId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>siteBy</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре site: внутренний ID (siteBy=id) или код (siteBy=code) магазина. По умолчанию code.</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Список избранного успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка изменения списка избранного</p></li></ul></td></tr></tbody></table>

### POST /api/v5/customer-interaction/{site}/favorites/{customerId}/remove

Удаление товарного предложения из списка избранного клиента

Для доступа к методу необходимо разрешение `customer_interaction_write`.

В параметре `{site}` можно передавать либо символьный код магазина либо ID магазина. По умолчанию будет использоваться символьный код. Для использования ID магазина также необходимо передать параметр `siteBy=id`.

Если товарного предложения нет в списке избранного, возвращается успешный ответ `success=true`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `favorite` | `object (SerializedFavorite)` |     |     |
| `favorite[offer]` | `object (SerializedRelationOffer)` |     | Торговое предложение |
| `favorite[offer][id]` | `integer` |     | ID торгового предложения |
| `favorite[offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `favorite[offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре customerId: внутренний (by=id) или внешний (by=externalId) ID клиента. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>siteBy</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>code</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре site: внутренний ID (siteBy=id) или код (siteBy=code) магазина. По умолчанию code.</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Список избранного успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка изменения списка избранного</p></li></ul></td></tr></tbody></table>

## Доставки

### POST /api/v5/delivery/calculate

Расчёт стоимости доставки

Метод рассчитывает стоимость доставки для выбранных типов доставок (`deliveryTypeCodes`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `deliveryTypeCodes[]` | `array of strings` |     | Коды типов доставок |
| `order` | `object (SerializedOrder)` |     |     |
| `order[weight]` | `double` |     | Вес |
| `order[length]` | `integer` |     | Длина |
| `order[width]` | `integer` |     | Ширина |
| `order[height]` | `integer` |     | Высота |
| `order[items][]` | `array of objects (SerializedOrderProduct)` |     |     |
| `order[items][][initialPrice]` | `double` |     | Цена товара/SKU (в валюте объекта) |
| `order[items][][discountManualAmount]` | `double` |     | Денежная скидка на единицу товара (в валюте объекта) |
| `order[items][][discountManualPercent]` | `double` |     | Процентная скидка на единицу товара |
| `order[items][][quantity]` | `float` |     | Количество |
| `order[delivery]` | `object (SerializedOrderDelivery)` |     | Данные о доставке |
| `order[delivery][date]` | `DateTime` | `Y-m-d` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` |     | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | `H:i` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | `H:i` | Время "до" |
| `order[delivery][time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` |     | Адрес доставки |
| `order[delivery][address][index]` | `string` |     | Индекс |
| `order[delivery][address][countryIso]` | `string` |     | ISO код страны |
| `order[delivery][address][region]` | `string` |     | Регион |
| `order[delivery][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` |     | Город |
| `order[delivery][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` |     | Тип населенного пункта |
| `order[delivery][address][street]` | `string` |     | Улица |
| `order[delivery][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` |     | Тип улицы |
| `order[delivery][address][building]` | `string` |     | Дом |
| `order[delivery][address][flat]` | `string` |     | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` |     | Этаж |
| `order[delivery][address][block]` | `integer` |     | Подъезд |
| `order[delivery][address][house]` | `string` |     | Строение |
| `order[delivery][address][housing]` | `string` |     | Корпус |
| `order[delivery][address][metro]` | `string` |     | Метро |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `calculations[]` | `array of objects (DeliveryCalculation)` |     |
| `calculations[][code]` | `string` | Код типа доставки |
| `calculations[][available]` | `boolean` | Тип доставки подходит по заданным условиям |
| `calculations[][vatRate]` | `string` | Ставка НДС |
| `calculations[][cost]` | `double` | Стоимость доставки |

### POST /api/v5/delivery/generic/{subcode}/tracking

Обновление статусов доставки

Метод позволяет передавать статусы отдельно для каждого заказа в момент смены статуса или передавать историю изменений по группе заказов через определенные промежутки на усмотрение службы доставки.

**_Важно:_** _За один запрос можно обновить статусы не более чем для_ **_100_** _заказов. При передаче большего числа заказов метод вернет сообщение об ошибке._

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `statusUpdate[]` | `array of objects (RequestStatusUpdateItem)` |     | JSON с данными по статусам заказов |
| `statusUpdate[][deliveryId]` | `string` |     | Идентификатор доставки в СД |
| `statusUpdate[][trackNumber]` | `string` |     | Трек номер (если установлена опция `configuration[allowTrackNumber]`) |
| `statusUpdate[][cost]` | `double` |     | Стоимость доставки |
| `statusUpdate[][history][]` | `array of objects (StatusInfo)` |     | История смены статусов доставки |
| `statusUpdate[][history][][code]` | `string` |     | Код статуса доставки |
| `statusUpdate[][history][][updatedAt]` | `DateTime` | `Y-m-d\TH:i:sP` | Дата обновления статуса доставки |
| `statusUpdate[][history][][comment]` | `string` |     | Комментарий к статусу |
| `statusUpdate[][extraData][]` | `array of strings` |     | Массив дополнительных данных доставки (`deliveryDataField.code => значение`) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### GET /api/v5/delivery/shipments

Получение списка отгрузок в службы доставки

Для доступа к методу необходимо разрешение `delivery_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (DeliveryShipmentFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Идентификаторы отгрузок |
| `filter[externalId]` | `string` |     | Внешний идентификатор отгрузки |
| `filter[orderNumber]` | `string` | `{length: {max: 255}}` | Номер заказа в составе отгрузки |
| `filter[deliveryTypes][]` | `array of strings` |     | Типы доставки |
| `filter[managers][]` | `array of integers` |     | Идентификаторы менеджеров |
| `filter[stores][]` | `array of strings` |     | Склады |
| `filter[statuses][]` | `array of strings` |     | Статусы |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Дата отгрузки (с) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Дата отгрузки (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `deliveryShipments[]` | `array of objects (DeliveryShipment)` | Заявка на отгрузку в службу доставки |
| `deliveryShipments[][integrationCode]` | `string` | Код интеграции |
| `deliveryShipments[][id]` | `integer` | Идентификатор отгрузки |
| `deliveryShipments[][externalId]` | `string` | Идентификатор отгрузки в службе доставки |
| `deliveryShipments[][deliveryType]` | `string` | Тип доставки |
| `deliveryShipments[][store]` | `string` | Склад отгрузки |
| `deliveryShipments[][managerId]` | `integer` | Менеджер, ответственный за отгрузку |
| `deliveryShipments[][status]` | `string` | Статус отгрузки (Возможные значения `created, processing, shipped, cancelled`) |
| `deliveryShipments[][date]` | `DateTime` | Дата отгрузки |
| `deliveryShipments[][time]` | `object (TimeInterval)` | Время отгрузки |
| `deliveryShipments[][time][from]` | `DateTime` | Время "с" |
| `deliveryShipments[][time][to]` | `DateTime` | Время "до" |
| `deliveryShipments[][time][custom]` | `string` | Временной диапазон в свободной форме |
| `deliveryShipments[][comment]` | `string` | Комментарий |
| `deliveryShipments[][orders][]` | `array of objects (SerializedEntityOrder)` | Заказы в составке отгрузки |
| `deliveryShipments[][orders][][id]` | `integer` | Внутренний ID заказа |
| `deliveryShipments[][orders][][externalId]` | `string` | Внешний ID заказа |
| `deliveryShipments[][orders][][number]` | `string` | Номер заказа |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/delivery/shipments/create

Создание отгрузки

Для доступа к методу необходимо разрешение `delivery_write`.

Набор полей зависит от типа доставки для которого создана отгрузка.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `deliveryType` | `string` |     | Тип доставки |
| `site` | `string` |     | Символьный код магазина (указывается в случае добавления заказов в отгрузку по externalId или number) |
| `deliveryShipment` | `object (DeliveryShipment)` |     | Заявка на отгрузку в службу доставки |
| `deliveryShipment[status]` | `string` |     | Статус отгрузки (Возможные значения `created, processing, shipped, cancelled`) |
| `deliveryShipment[date]` | `DateTime` |     | Дата отгрузки |
| `deliveryShipment[time]` | `object (TimeInterval)` |     | Время отгрузки |
| `deliveryShipment[time][from]` | `DateTime` |     | Время "с" |
| `deliveryShipment[time][to]` | `DateTime` |     | Время "до" |
| `deliveryShipment[time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `deliveryShipment[comment]` | `string` |     | Комментарий |
| `deliveryShipment[store]` | `string` |     | Склад отгрузки |
| `deliveryShipment[managerId]` | `integer` |     | Менеджер, ответственный за отгрузку |
| `deliveryShipment[orders][]` | `array of objects (SerializedEntityOrder)` |     | Заказы в составке отгрузки |
| `deliveryShipment[orders][][id]` | `integer` |     | Внутренний ID заказа |
| `deliveryShipment[orders][][externalId]` | `string` |     | Внешний ID заказа |
| `deliveryShipment[orders][][number]` | `string` |     | Номер заказа |
| `deliveryShipment[extraData]` | `array` |     | Дополнительные данные отгрузки (`shipmentDataField.code => значение`) (указывается только для отгрузок для типов доставок, интегрированных со службами доставки, подключенными через API) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Идентификатор отгрузки |
| `status` | `string` | Статус отгрузки |

### GET /api/v5/delivery/shipments/{id}

Получение информации об отгрузке

Для доступа к методу необходимо разрешение `delivery_read`.

Набор полей зависит от типа доставки для которого создана отгрузка.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `string` |     | Идентификатор отгрузки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `deliveryShipment` | `object (DeliveryShipment)` | Заявка на отгрузку в службу доставки |
| `deliveryShipment[integrationCode]` | `string` | Код интеграции |
| `deliveryShipment[id]` | `integer` | Идентификатор отгрузки |
| `deliveryShipment[externalId]` | `string` | Идентификатор отгрузки в службе доставки |
| `deliveryShipment[deliveryType]` | `string` | Тип доставки |
| `deliveryShipment[store]` | `string` | Склад отгрузки |
| `deliveryShipment[managerId]` | `integer` | Менеджер, ответственный за отгрузку |
| `deliveryShipment[status]` | `string` | Статус отгрузки (Возможные значения `created, processing, shipped, cancelled`) |
| `deliveryShipment[date]` | `DateTime` | Дата отгрузки |
| `deliveryShipment[time]` | `object (TimeInterval)` | Время отгрузки |
| `deliveryShipment[time][from]` | `DateTime` | Время "с" |
| `deliveryShipment[time][to]` | `DateTime` | Время "до" |
| `deliveryShipment[time][custom]` | `string` | Временной диапазон в свободной форме |
| `deliveryShipment[comment]` | `string` | Комментарий |
| `deliveryShipment[orders][]` | `array of objects (SerializedEntityOrder)` | Заказы в составке отгрузки |
| `deliveryShipment[orders][][id]` | `integer` | Внутренний ID заказа |
| `deliveryShipment[orders][][externalId]` | `string` | Внешний ID заказа |
| `deliveryShipment[orders][][number]` | `string` | Номер заказа |
| `deliveryShipment[extraData]` | `array` | Дополнительные данные отгрузки (`shipmentDataField.code => значение`) (указывается только для отгрузок для типов доставок, интегрированных со службами доставки, подключенными через API) |

### POST /api/v5/delivery/shipments/{id}/edit

Редактироване отгрузки

Для доступа к методу необходимо разрешение `delivery_write`.

Редактирование отгрузки возможно только для отгрузок в статусе `created`

Набор полей зависит от типа доставки для которого создана отгрузка.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина (указывается в случае добавления заказов в отгрузку по externalId или number) |
| `deliveryShipment` | `object (DeliveryShipment)` |     | Заявка на отгрузку в службу доставки |
| `deliveryShipment[status]` | `string` |     | Статус отгрузки (Возможные значения `created, processing, shipped, cancelled`) |
| `deliveryShipment[date]` | `DateTime` |     | Дата отгрузки |
| `deliveryShipment[time]` | `object (TimeInterval)` |     | Время отгрузки |
| `deliveryShipment[time][from]` | `DateTime` |     | Время "с" |
| `deliveryShipment[time][to]` | `DateTime` |     | Время "до" |
| `deliveryShipment[time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `deliveryShipment[comment]` | `string` |     | Комментарий |
| `deliveryShipment[store]` | `string` |     | Склад отгрузки |
| `deliveryShipment[managerId]` | `integer` |     | Менеджер, ответственный за отгрузку |
| `deliveryShipment[orders][]` | `array of objects (SerializedEntityOrder)` |     | Заказы в составке отгрузки |
| `deliveryShipment[orders][][id]` | `integer` |     | Внутренний ID заказа |
| `deliveryShipment[orders][][externalId]` | `string` |     | Внешний ID заказа |
| `deliveryShipment[orders][][number]` | `string` |     | Номер заказа |
| `deliveryShipment[extraData]` | `array` |     | Дополнительные данные отгрузки (`shipmentDataField.code => значение`) (указывается только для отгрузок для типов доставок, интегрированных со службами доставки, подключенными через API) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Идентификатор отгрузки |
| `status` | `string` | Статус отгрузки |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["autocomplete"\]}

Запрос на получение данных для автокомплит поля

При работе с autocomplete-полями, заданными в конфигурации `integrationModule[integrations][delivery]["deliveryDataFieldList"]`, система будет инициализировать запрос к службе доставки используя GET запрос метода указанного в `autocompleteUrl` конфигурации соответствующего поля.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `term` | `string` |     | Строка запроса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result[]` | `array of objects (ResponseAutocompleteItem)` | Массив значений |
| `result[][value]` | `string` | Значение |
| `result[][label]` | `string` | Наименование |
| `result[][description]` | `string` | Не обязательное поле. Подсказка для опции - выводится мелким шрифтом под именем опции |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["calculate"\]}Расчет стоимости доставки

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["delete"\]}

Удаление заявки на доставку

Для удаления доставки система инициирует POST вызов метода указанного в `integrationModule[integrations][delivery]["actions"]["delete"]` кофигурации.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `delete` | `object (RequestDelete)` |     | JSON с идентификатором доставки |
| `delete[deliveryId]` | `string` |     | Идентификатор доставки в службе доставки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["get"\]}

Получение данных доставки

Для получения данных для доставки система инициирует GET запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["get"]`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `deliveryId` | `string` |     | Идентификатор доставки в службе доставки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result` | `object (ResponseLoadDeliveryData)` | Данные доставки |
| `result[trackNumber]` | `string` | Трек номер (если установлена опция `configuration[allowTrackNumber]`) |
| `result[cost]` | `float` | Стоимость доставки |
| `result[shipmentDate]` | `DateTime` | Дата отгрузки |
| `result[deliveryDate]` | `DateTime` | Дата доставки |
| `result[deliveryTime]` | `object (TimeInterval)` | Время доставки |
| `result[deliveryTime][from]` | `DateTime` | Время "с" |
| `result[deliveryTime][to]` | `DateTime` | Время "до" |
| `result[deliveryTime][custom]` | `string` | Временной диапазон в свободной форме |
| `result[tariff]` | `string` | Код тарифа |
| `result[tariffName]` | `string` | Наименование тарифа |
| `result[payerType]` | `string` | Плательщик за доставку (`receiver` или `sender`) |
| `result[status]` | `object (StatusInfo)` | Статус доставки |
| `result[status][code]` | `string` | Код статуса доставки |
| `result[status][updatedAt]` | `DateTime` | Дата обновления статуса доставки |
| `result[status][comment]` | `string` | Комментарий к статусу |
| `result[extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `result[shipmentAddress]` | `object (DeliveryAddress)` | Адрес отгрузки |
| `result[shipmentAddress][index]` | `string` | Индекс |
| `result[shipmentAddress][countryIso]` | `string` | ISO код страны (ISO 3166-1 alpha-2) |
| `result[shipmentAddress][region]` | `string` | Регион |
| `result[shipmentAddress][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `result[shipmentAddress][city]` | `string` | Город |
| `result[shipmentAddress][cityId]` | `integer` | Идентификатор города в Geohelper |
| `result[shipmentAddress][cityType]` | `string` | Тип населенного пункта |
| `result[shipmentAddress][street]` | `string` | Улица |
| `result[shipmentAddress][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `result[shipmentAddress][streetType]` | `string` | Тип улицы |
| `result[shipmentAddress][building]` | `string` | Дом |
| `result[shipmentAddress][flat]` | `string` | Номер квартиры/офиса |
| `result[shipmentAddress][floor]` | `integer` | Этаж |
| `result[shipmentAddress][block]` | `integer` | Подъезд |
| `result[shipmentAddress][house]` | `string` | Строение |
| `result[shipmentAddress][housing]` | `string` | Корпус |
| `result[shipmentAddress][metro]` | `string` | Метро |
| `result[shipmentAddress][notes]` | `string` | Примечания к адресу |
| `result[shipmentAddress][text]` | `string` | Адрес в текстовом виде |
| `result[shipmentAddress][terminal]` | `string` | Код терминала отгрузки/доставки |
| `result[shipmentAddress][terminalData]` | `object (Terminal)` | Данные терминала |
| `result[shipmentAddress][terminalData][code]` | `string` | Код терминала |
| `result[shipmentAddress][terminalData][cost]` | `float` | Стоимость доставки до терминала (указывается в случае если она отличается от стандартной стоимости по тарифу) |
| `result[shipmentAddress][terminalData][name]` | `string` | Наименование терминала |
| `result[shipmentAddress][terminalData][description]` | `string` | Описание терминала |
| `result[shipmentAddress][terminalData][address]` | `string` | Адрес |
| `result[shipmentAddress][terminalData][schedule]` | `string` | Режим работы |
| `result[shipmentAddress][terminalData][phone]` | `string` | Телефон |
| `result[shipmentAddress][terminalData][extraData]` | `array` | Дополнительные данные (`deliveryDataField.code => значение`) |
| `result[shipmentAddress][terminalData][coordinates]` | `object (Coordinates)` | Координаты |
| `result[shipmentAddress][terminalData][coordinates][latitude]` | `string` | Широта |
| `result[shipmentAddress][terminalData][coordinates][longitude]` | `string` | Долгота |
| `result[deliveryAddress]` | `object (DeliveryAddress)` | Адрес доставки |
| `result[deliveryAddress][index]` | `string` | Индекс |
| `result[deliveryAddress][countryIso]` | `string` | ISO код страны (ISO 3166-1 alpha-2) |
| `result[deliveryAddress][region]` | `string` | Регион |
| `result[deliveryAddress][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `result[deliveryAddress][city]` | `string` | Город |
| `result[deliveryAddress][cityId]` | `integer` | Идентификатор города в Geohelper |
| `result[deliveryAddress][cityType]` | `string` | Тип населенного пункта |
| `result[deliveryAddress][street]` | `string` | Улица |
| `result[deliveryAddress][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `result[deliveryAddress][streetType]` | `string` | Тип улицы |
| `result[deliveryAddress][building]` | `string` | Дом |
| `result[deliveryAddress][flat]` | `string` | Номер квартиры/офиса |
| `result[deliveryAddress][floor]` | `integer` | Этаж |
| `result[deliveryAddress][block]` | `integer` | Подъезд |
| `result[deliveryAddress][house]` | `string` | Строение |
| `result[deliveryAddress][housing]` | `string` | Корпус |
| `result[deliveryAddress][metro]` | `string` | Метро |
| `result[deliveryAddress][notes]` | `string` | Примечания к адресу |
| `result[deliveryAddress][text]` | `string` | Адрес в текстовом виде |
| `result[deliveryAddress][terminal]` | `string` | Код терминала отгрузки/доставки |
| `result[deliveryAddress][terminalData]` | `object (Terminal)` | Данные терминала |
| `result[deliveryAddress][terminalData][code]` | `string` | Код терминала |
| `result[deliveryAddress][terminalData][cost]` | `float` | Стоимость доставки до терминала (указывается в случае если она отличается от стандартной стоимости по тарифу) |
| `result[deliveryAddress][terminalData][name]` | `string` | Наименование терминала |
| `result[deliveryAddress][terminalData][description]` | `string` | Описание терминала |
| `result[deliveryAddress][terminalData][address]` | `string` | Адрес |
| `result[deliveryAddress][terminalData][schedule]` | `string` | Режим работы |
| `result[deliveryAddress][terminalData][phone]` | `string` | Телефон |
| `result[deliveryAddress][terminalData][extraData]` | `array` | Дополнительные данные (`deliveryDataField.code => значение`) |
| `result[deliveryAddress][terminalData][coordinates]` | `object (Coordinates)` | Координаты |
| `result[deliveryAddress][terminalData][coordinates][latitude]` | `string` | Широта |
| `result[deliveryAddress][terminalData][coordinates][longitude]` | `string` | Долгота |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["print"\]}

Печатные формы службы доставки

Для печати форм указанных при конфигурации в `integrationModule[integrations][delivery]["plateList"]` система инициирует POST запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["print"]`.

Служба доставки должна сформировать pdf-файл печатной формы и вернуть его в виде байтового массива

Если требуется показать пользователю сообщение об ошибке, то нужно вернуть ответ с кодом 400. Содержимое ответа должно быть [стандартным ответом с сообщением об ошибке](https://docs.retailcrm.ru/Developers/API/APIFeatures/APIErrorMessage).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `print` | `object (RequestPrint)` |     | JSON со списком заказов для печати |
| `print[entityType]` | `string` |     | Тип сущности для печатной формы (`order` - печатная форма для заказа (по умолчанию), `shipment` - печатная форма для отгрузки. Значение совпадает со значением `integrationModule[integrations][delivery][plateList][][type]` выбранной печатной формы) |
| `print[type]` | `string` |     | Код типа печатной формы |
| `print[deliveryIds]` | `array` |     | Массив идентификаторов доставок в службе доставки (`[["56376", "798645"]]`) |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["save"\]}

Создание и редактирование доставки

Для создания новой доставки система инициирует POST вызов метода указанного в `integrationModule[integrations][delivery]["actions"]["save"]` кофигурации. Запрос на редактирование доставки аналогичен запросу на создание, но необходимо передавать идентификатор заказа в службе доставки `save["deliveryId"]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `save` | `object (RequestSave)` |     | JSON с данными для создания доставки |
| `save[deliveryId]` | `string` |     | Идентификатор доставки в службе доставки. Передается если требуется отредактировать уже оформленную доставку |
| `save[order]` | `string` |     | Внутренний ID заказа |
| `save[orderNumber]` | `string` |     | Номер заказа |
| `save[site]` | `string` |     | Код магазина |
| `save[siteName]` | `string` |     | Наименование магазина |
| `save[store]` | `object (Store)` |     | Склад отгрузки |
| `save[store][code]` | `string` |     | Символьный код |
| `save[store][name]` | `string` |     | Название |
| `save[store][address]` | `object (StoreAddress)` |     | Адрес склада |
| `save[store][address][index]` | `string` |     | Индекс |
| `save[store][address][countryIso]` | `string` |     | ISO код страны |
| `save[store][address][region]` | `string` |     | Регион |
| `save[store][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `save[store][address][city]` | `string` |     | Город |
| `save[store][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `save[store][address][cityType]` | `string` |     | Тип населенного пункта |
| `save[store][address][street]` | `string` |     | Улица |
| `save[store][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `save[store][address][streetType]` | `string` |     | Тип улицы |
| `save[store][address][building]` | `string` |     | Дом |
| `save[store][address][flat]` | `string` |     | Номер квартиры/офиса |
| `save[store][address][floor]` | `integer` |     | Этаж |
| `save[store][address][block]` | `integer` |     | Подъезд |
| `save[store][address][house]` | `string` |     | Строение |
| `save[store][address][housing]` | `string` |     | Корпус |
| `save[store][address][metro]` | `string` |     | Метро |
| `save[store][address][notes]` | `string` |     | Примечания к адресу |
| `save[store][address][text]` | `string` |     | Адрес в текстовом виде |
| `save[store][address][coordinates]` | `object (Point)` |     | Координаты точки |
| `save[store][address][coordinates][latitude]` | `float` |     | Широта |
| `save[store][address][coordinates][longitude]` | `float` |     | Долгота |
| `save[store][workTime]` | `object (SerializedStoreWeekOpeningHours)` |     | Время работы склада |
| `save[store][workTime][mo][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в понедельник |
| `save[store][workTime][mo][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][mo][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][mo][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][mo][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][tu][]` | `array of objects (StoreWorkTime)` |     | Время работы склада во вторник |
| `save[store][workTime][tu][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][tu][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][tu][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][tu][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][we][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в среду |
| `save[store][workTime][we][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][we][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][we][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][we][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][th][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в четверг |
| `save[store][workTime][th][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][th][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][th][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][th][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][fr][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в пятницу |
| `save[store][workTime][fr][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][fr][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][fr][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][fr][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][sa][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в субботу |
| `save[store][workTime][sa][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][sa][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][sa][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][sa][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[store][workTime][su][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в воскресенье |
| `save[store][workTime][su][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `save[store][workTime][su][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `save[store][workTime][su][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `save[store][workTime][su][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `save[legalEntity]` | `string` |     | Наименование юридического лица продавца |
| `save[customer]` | `object (Customer)` |     | Покупатель |
| `save[customer][id]` | `integer` |     | Идентификатор покупателя |
| `save[customer][lastName]` | `string` |     | Фамилия |
| `save[customer][firstName]` | `string` |     | Имя |
| `save[customer][patronymic]` | `string` |     | Отчество |
| `save[customer][phones][]` | `array of strings` |     | Телефоны |
| `save[customer][email]` | `string` |     | E-mail |
| `save[customer][contragent]` | `object (Contragent)` |     | Данные контрагента |
| `save[customer][contragent][type]` | `string` |     | Тип контрагента |
| `save[customer][contragent][legalName]` | `string` |     | Полное наименование |
| `save[customer][contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `save[customer][contragent][INN]` | `string` |     | ИНН |
| `save[customer][contragent][OKPO]` | `string` |     | ОКПО |
| `save[customer][contragent][KPP]` | `string` |     | КПП |
| `save[customer][contragent][OGRN]` | `string` |     | ОГРН |
| `save[customer][contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `save[manager]` | `object (Manager)` |     | Менеджер, работающий с покупателем |
| `save[manager][id]` | `integer` |     | Идентификатор менеджера |
| `save[manager][lastName]` | `string` |     | Фамилия |
| `save[manager][firstName]` | `string` |     | Имя |
| `save[manager][patronymic]` | `string` |     | Отчество |
| `save[manager][phone]` | `string` |     | Телефон |
| `save[manager][email]` | `string` |     | E-mail |
| `save[packages][]` | `array of objects (Package)` |     | Набор упаковок |
| `save[packages][][packageId]` | `string` |     | Идентификатор упаковки |
| `save[packages][][weight]` | `float` |     | Вес г. |
| `save[packages][][width]` | `integer` |     | Ширина мм. |
| `save[packages][][length]` | `integer` |     | Длина мм. |
| `save[packages][][height]` | `integer` |     | Высота мм. |
| `save[packages][][items][]` | `array of objects (PackageItem)` |     | Содержимое упаковки |
| `save[packages][][items][][offerId]` | `string` |     | Идентификатор оффера в системе |
| `save[packages][][items][][externalId]` | `string` |     | Идентификатор торгового предложения в магазине |
| `save[packages][][items][][xmlId]` | `string` |     | Идентификатор торгового предложения в складской системе |
| `save[packages][][items][][name]` | `string` |     | Наименование товара |
| `save[packages][][items][][declaredValue]` | `float` |     | Объявленная стоимость за единицу товара |
| `save[packages][][items][][cod]` | `float` |     | Наложенный платеж за единицу товара |
| `save[packages][][items][][vatRate]` | `string` |     | Ставка НДС (`"none" - НДС не облагается`) |
| `save[packages][][items][][quantity]` | `float` |     | Количество товара в упаковке |
| `save[packages][][items][][unit]` | `object (Unit)` |     | Единица измерения товара |
| `save[packages][][items][][unit][code]` | `string` |     | Символьный код |
| `save[packages][][items][][unit][name]` | `string` |     | Название |
| `save[packages][][items][][unit][sym]` | `string` |     | Краткое обозначение |
| `save[packages][][items][][cost]` | `float` |     | Стоимость товара (с учетом скидок) |
| `save[packages][][items][][markingCodes][]` | `array of strings` |     | Коды маркировки ([формат кода маркировки](https://docs.retailcrm.ru/Users/OperatingActivity/Warehouses/MarkingOfGoods/MarkingOfGoodsInOrder#h-1)) |
| `save[packages][][items][][properties][]` | `array of arrays` |     | Свойства товара |
| `save[packages][][items][][weight]` | `float` |     | Вес товара (может быть `null` для услуг) |
| `save[delivery]` | `object (SaveDeliveryData)` |     | Данные доставки |
| `save[delivery][shipmentAddress]` | `object (DeliveryAddress)` |     | Адрес отгрузки |
| `save[delivery][shipmentAddress][index]` | `string` |     | Индекс |
| `save[delivery][shipmentAddress][countryIso]` | `string` |     | ISO код страны (ISO 3166-1 alpha-2) |
| `save[delivery][shipmentAddress][region]` | `string` |     | Регион |
| `save[delivery][shipmentAddress][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `save[delivery][shipmentAddress][city]` | `string` |     | Город |
| `save[delivery][shipmentAddress][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `save[delivery][shipmentAddress][cityType]` | `string` |     | Тип населенного пункта |
| `save[delivery][shipmentAddress][street]` | `string` |     | Улица |
| `save[delivery][shipmentAddress][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `save[delivery][shipmentAddress][streetType]` | `string` |     | Тип улицы |
| `save[delivery][shipmentAddress][building]` | `string` |     | Дом |
| `save[delivery][shipmentAddress][flat]` | `string` |     | Номер квартиры/офиса |
| `save[delivery][shipmentAddress][floor]` | `integer` |     | Этаж |
| `save[delivery][shipmentAddress][block]` | `integer` |     | Подъезд |
| `save[delivery][shipmentAddress][house]` | `string` |     | Строение |
| `save[delivery][shipmentAddress][housing]` | `string` |     | Корпус |
| `save[delivery][shipmentAddress][metro]` | `string` |     | Метро |
| `save[delivery][shipmentAddress][notes]` | `string` |     | Примечания к адресу |
| `save[delivery][shipmentAddress][text]` | `string` |     | Адрес в текстовом виде |
| `save[delivery][shipmentAddress][terminal]` | `string` |     | Код терминала отгрузки/доставки |
| `save[delivery][deliveryAddress]` | `object (DeliveryAddress)` |     | Адрес доставки |
| `save[delivery][deliveryAddress][index]` | `string` |     | Индекс |
| `save[delivery][deliveryAddress][countryIso]` | `string` |     | ISO код страны (ISO 3166-1 alpha-2) |
| `save[delivery][deliveryAddress][region]` | `string` |     | Регион |
| `save[delivery][deliveryAddress][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `save[delivery][deliveryAddress][city]` | `string` |     | Город |
| `save[delivery][deliveryAddress][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `save[delivery][deliveryAddress][cityType]` | `string` |     | Тип населенного пункта |
| `save[delivery][deliveryAddress][street]` | `string` |     | Улица |
| `save[delivery][deliveryAddress][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `save[delivery][deliveryAddress][streetType]` | `string` |     | Тип улицы |
| `save[delivery][deliveryAddress][building]` | `string` |     | Дом |
| `save[delivery][deliveryAddress][flat]` | `string` |     | Номер квартиры/офиса |
| `save[delivery][deliveryAddress][floor]` | `integer` |     | Этаж |
| `save[delivery][deliveryAddress][block]` | `integer` |     | Подъезд |
| `save[delivery][deliveryAddress][house]` | `string` |     | Строение |
| `save[delivery][deliveryAddress][housing]` | `string` |     | Корпус |
| `save[delivery][deliveryAddress][metro]` | `string` |     | Метро |
| `save[delivery][deliveryAddress][notes]` | `string` |     | Примечания к адресу |
| `save[delivery][deliveryAddress][text]` | `string` |     | Адрес в текстовом виде |
| `save[delivery][deliveryAddress][terminal]` | `string` |     | Код терминала отгрузки/доставки |
| `save[delivery][codPaymentType]` | `object (PaymentType)` |     | Тип оплаты для наложенного платежа |
| `save[delivery][codPaymentType][code]` | `string` |     | Символьный код |
| `save[delivery][codPaymentType][name]` | `string` |     | Название |
| `save[delivery][withCod]` | `boolean` |     | Доставка наложенным платежом |
| `save[delivery][cod]` | `float` |     | Величина наложенного платежа за услуги доставки |
| `save[delivery][cost]` | `float` |     | Стоимость доставки (указывается в накладной в случае предоплаты) |
| `save[delivery][vatRate]` | `string` |     | Ставка НДС на услугу доставки (`"none" - НДС не облагается`) |
| `save[delivery][tariff]` | `string` |     | Код тарифа |
| `save[delivery][payerType]` | `string` |     | Плательщик за услуги доставки (`receiver` или `sender`) |
| `save[delivery][shipmentDate]` | `DateTime` | `Y-m-d` | Дата отгрузки |
| `save[delivery][deliveryDate]` | `DateTime` | `Y-m-d` | Дата доставки |
| `save[delivery][deliveryTime]` | `object (TimeInterval)` |     | Время доставки ("custom" не ипользуется) |
| `save[delivery][deliveryTime][from]` | `DateTime` | `H:i` | Время "с" |
| `save[delivery][deliveryTime][to]` | `DateTime` | `H:i` | Время "до" |
| `save[delivery][deliveryTime][custom]` | `string` |     | Временной диапазон в свободной форме |
| `save[delivery][extraData][]` | `array of objects (ExtraDataValue)` |     | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `save[currency]` | `string` |     | Код валюты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result` | `object (ResponseSave)` | Результат оформления доставки |
| `result[deliveryId]` | `string` | Идентификатор доставки в службе доставки |
| `result[trackNumber]` | `string` | Трек номер (если установлена опция `configuration[allowTrackNumber]`) |
| `result[cost]` | `float` | Стоимость доставки |
| `result[status]` | `string` | Код статуса доставки |
| `result[extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["shipmentDelete"\]}

Удаление заявки на отгрузку

Для удаления заявки на отгрузку система инициирует POST запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["shipmentDelete"]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `shipmentDelete` | `object (RequestShipmentDelete)` |     | JSON с данными для удаления заявки на отгрузку |
| `shipmentDelete[shipmentId]` | `string` |     | Идентификатор отгрузки в службе доставки |
| `shipmentDelete[extraData][]` | `array of objects (ExtraDataValue)` |     | Дополнительные данные отгрузки (`shipmentDataField.code => значение`) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["shipmentPointList"\]}

Список терминалов приема посылок

Для работы с терминалами система инициирует GET запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["shipmentPointList"]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `country` | `string` |     | ISO код страны (ISO 3166-1 alpha-2) |
| `region` | `string` |     | Регион |
| `regionId` | `integer` |     | Идентификатор региона в Geohelper |
| `city` | `string` |     | Город |
| `cityId` | `integer` |     | Идентификатор города в Geohelper |
| `code` | `string` |     | Код склада отгрузки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result[]` | `array of objects (Terminal)` | Терминал отгрузки/получения |
| `result[][code]` | `string` | Код терминала |
| `result[][cost]` | `float` | Стоимость доставки до терминала (указывается в случае если она отличается от стандартной стоимости по тарифу) |
| `result[][name]` | `string` | Наименование терминала |
| `result[][description]` | `string` | Описание терминала |
| `result[][address]` | `string` | Адрес |
| `result[][schedule]` | `string` | Режим работы |
| `result[][phone]` | `string` | Телефон |
| `result[][extraData]` | `array` | Дополнительные данные (`deliveryDataField.code => значение`) |
| `result[][coordinates]` | `object (Coordinates)` | Координаты |
| `result[][coordinates][latitude]` | `string` | Широта |
| `result[][coordinates][longitude]` | `string` | Долгота |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["shipmentSave"\]}

Создание и редактирование отгрузки

Для создания/редактирования заявки на отгрузку система инициирует POST запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["shipmentSave"]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `shipmentSave` | `object (RequestShipmentSave)` |     | JSON с данными для создания отгрузки |
| `shipmentSave[shipmentId]` | `string` |     | Идентификатор отгрузки в службе доставки. Передается если требуется отредактировать уже оформленную отгрузку |
| `shipmentSave[manager]` | `object (Manager)` |     | Менеджер ответственный за отгрузку |
| `shipmentSave[manager][id]` | `integer` |     | Идентификатор менеджера |
| `shipmentSave[manager][lastName]` | `string` |     | Фамилия |
| `shipmentSave[manager][firstName]` | `string` |     | Имя |
| `shipmentSave[manager][patronymic]` | `string` |     | Отчество |
| `shipmentSave[manager][phone]` | `string` |     | Телефон |
| `shipmentSave[manager][email]` | `string` |     | E-mail |
| `shipmentSave[date]` | `DateTime` | `Y-m-d` | Дата отгрузки |
| `shipmentSave[time]` | `object (TimeInterval)` |     | Время отгрузки |
| `shipmentSave[time][from]` | `DateTime` | `H:i` | Время "с" |
| `shipmentSave[time][to]` | `DateTime` | `H:i` | Время "до" |
| `shipmentSave[time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `shipmentSave[address]` | `object (DeliveryAddress)` |     | Адрес отгрузки |
| `shipmentSave[address][index]` | `string` |     | Индекс |
| `shipmentSave[address][countryIso]` | `string` |     | ISO код страны (ISO 3166-1 alpha-2) |
| `shipmentSave[address][region]` | `string` |     | Регион |
| `shipmentSave[address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `shipmentSave[address][city]` | `string` |     | Город |
| `shipmentSave[address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `shipmentSave[address][cityType]` | `string` |     | Тип населенного пункта |
| `shipmentSave[address][street]` | `string` |     | Улица |
| `shipmentSave[address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `shipmentSave[address][streetType]` | `string` |     | Тип улицы |
| `shipmentSave[address][building]` | `string` |     | Дом |
| `shipmentSave[address][flat]` | `string` |     | Номер квартиры/офиса |
| `shipmentSave[address][floor]` | `integer` |     | Этаж |
| `shipmentSave[address][block]` | `integer` |     | Подъезд |
| `shipmentSave[address][house]` | `string` |     | Строение |
| `shipmentSave[address][housing]` | `string` |     | Корпус |
| `shipmentSave[address][metro]` | `string` |     | Метро |
| `shipmentSave[address][notes]` | `string` |     | Примечания к адресу |
| `shipmentSave[address][text]` | `string` |     | Адрес в текстовом виде |
| `shipmentSave[address][terminal]` | `string` |     | Код терминала отгрузки/доставки |
| `shipmentSave[store]` | `string` |     | Склад отгрузки |
| `shipmentSave[orders][]` | `array of objects (ShipmentOrder)` |     | Заказы в составе отгрузки |
| `shipmentSave[orders][][deliveryId]` | `string` |     | Идентификатор оформленной доставки в службе доставки |
| `shipmentSave[orders][][packages][]` | `array of objects (Package)` |     | Упаковки |
| `shipmentSave[orders][][packages][][packageId]` | `string` |     | Идентификатор упаковки |
| `shipmentSave[orders][][packages][][weight]` | `float` |     | Вес г. |
| `shipmentSave[orders][][packages][][width]` | `integer` |     | Ширина мм. |
| `shipmentSave[orders][][packages][][length]` | `integer` |     | Длина мм. |
| `shipmentSave[orders][][packages][][height]` | `integer` |     | Высота мм. |
| `shipmentSave[comment]` | `string` |     | Комментарий |
| `shipmentSave[extraData][]` | `array of objects (ExtraDataValue)` |     | Дополнительные данные отгрузки (`shipmentDataField.code => значение`) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result` | `object (ResponseShipmentSave)` | Результат оформления отгрузки |
| `result[shipmentId]` | `string` | Идентификатор отгрузки в службе доставки |
| `result[extraData]` | `array` | Дополнительные данные отгрузки |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["delivery"\]\["actions"\]\["tariffList"\]}

Список тарифов

Для получения списка тарифов система инициирует GET запрос метода указанного в `integrationModule[integrations][delivery]["actions"]["tariffList"]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result[]` | `array of objects (Tariff)` | Тариф |
| `result[][code]` | `string` | Код тарифа |
| `result[][name]` | `string` | Название тарифа |
| `result[][description]` | `string` | Описание тарифа |
| `result[][type]` | `string` | Тип тарифа (Возможные значения: `courier` - курьерская доставка, `selfDelivery` - самовывоз) |

## Файлы

### GET /api/v5/files

Получение списка файлов, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `file_read`.

Метод возвращает список файлов и информацию о них. Возвращаются только загруженные через API файлы, либо файлы, добавленные к заказу или клиенту.

В фильтрах `filter[ids][]`, `filter[orderIds][]`, `filter[customerIds][]` указывается массив внутренних ID элементов в системе.

В фильтрах `filter[customerExternalIds][]`, `filter[orderExternalIds][]` указывается массив внешних ID элементов в системе.

В фильтре `filter[type][]` указывается массив MIME типов файла.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (FileFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID файлов |
| `filter[orderIds][]` | `array of integers` |     | Массив внутренних ID заказов |
| `filter[orderExternalIds][]` | `array of strings` |     | Массив внешних ID заказов |
| `filter[customerIds][]` | `array of integers` |     | Массив внутренних ID клиентов |
| `filter[customerExternalIds][]` | `array of strings` |     | Массив внешних ID клиентов |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[type][]` | `array of strings` |     | Массив MIME типов файлов |
| `filter[filename]` | `string` |     | Название файла |
| `filter[isAttached]` | `boolean` |     | Привязан ли файл хотя бы к одному заказу или клиенту |
| `filter[createdAtFrom]` | `DateTime` | `Y-m-d` | Дата загрузки файла (от) |
| `filter[createdAtTo]` | `DateTime` | `Y-m-d` | Дата загрузки файла (до) |
| `filter[sizeFrom]` | `integer` |     | Размер файла (от) |
| `filter[sizeTo]` | `integer` |     | Размер файла (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `files[]` | `array of objects (File)` | Файл |
| `files[][id]` | `integer` | ID файла |
| `files[][filename]` | `string` | Имя файла |
| `files[][type]` | `string` | MIME-тип файла |
| `files[][createdAt]` | `DateTime` | Дата создания |
| `files[][size]` | `integer` | Размер файла в байтах |
| `files[][attachment][]` | `array of objects (Attachment)` | Прикрепленный объект (вложение) |
| `files[][attachment][][customer]` | `custom handler result for (Classes)` |     |
| `files[][attachment][][order]` | `object (Order)` | Заказ |
| `files[][attachment][][order][id]` | `integer` | ID заказа |
| `files[][attachment][][order][number]` | `string` | Номер заказа |
| `files[][attachment][][order][externalId]` | `string` | Внешний ID заказа |
| `files[][attachment][][order][site]` | `string` | Магазин |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/files/upload

Загрузка файла на сервер

Для доступа к методу необходимо разрешение `file_write`.

Метод позволяет загрузить файл. Для загрузки файла необходимо поместить его содержимое в тело запроса. Некоторые типы файлов загружать запрещено. Загруженному через API файлу автоматически присваивается имя _API upload_.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `file` | `object (File)` | Файл |
| `file[id]` | `integer` | ID файла |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/files/{id}

Получение информации о файле

Для доступа к методу необходимо разрешение `file_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID файла |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `file` | `object (File)` | Файл |
| `file[id]` | `integer` | ID файла |
| `file[filename]` | `string` | Имя файла |
| `file[type]` | `string` | MIME-тип файла |
| `file[createdAt]` | `DateTime` | Дата создания |
| `file[size]` | `integer` | Размер файла в байтах |
| `file[attachment][]` | `array of objects (Attachment)` | Прикрепленный объект (вложение) |
| `file[attachment][][customer]` | `custom handler result for (Classes)` |     |
| `file[attachment][][order]` | `object (Order)` | Заказ |
| `file[attachment][][order][id]` | `integer` | ID заказа |
| `file[attachment][][order][number]` | `string` | Номер заказа |
| `file[attachment][][order][externalId]` | `string` | Внешний ID заказа |
| `file[attachment][][order][site]` | `string` | Магазин |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Файл не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/files/{id}/delete

Удаление файла

Для доступа к методу необходимо разрешение `file_write`.

Метод позволяет удалить файл вместе со всеми связями с заказами и клиентами.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID файла |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Файл не найден</p></li></ul></td></tr></tbody></table>

### GET /api/v5/files/{id}/download

Скачивание файла

Для доступа к методу необходимо разрешение `file_read`.

Метод позволяет скачать файл. При скачивании содержимое файла отдаётся в виде потока, название файла отдаётся в HTTP заголовке `Content-Disposition`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID файла |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/files/{id}/edit

Редактирование файла

Для доступа к методу необходимо разрешение `file_write`.

Метод позволяет отредактировать имя файла и связи с заказами и клиентами. В `file[attachment][]` передаются все необходимые связи. Связи, не имеющиеся в этом поле, будут удалены.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `file` | `object (SerializedFile)` |     |     |
| `file[filename]` | `string` | `{length: {max: 1024}}{match: ([^\w\s_\.\-\(\):])}}` | Имя файла |
| `file[attachment][]` | `array of objects (SerializedAttachment)` |     |     |
| `file[attachment][][customer]` | `object (SerializedRelationAbstractCustomer)` |     | Клиент |
| `file[attachment][][customer][id]` | `integer` |     | Внутренний ID клиента |
| `file[attachment][][customer][externalId]` | `string` |     | Внешний ID клиента |
| `file[attachment][][customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `file[attachment][][customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `file[attachment][][order]` | `object (SerializedRelationOrder)` |     | Заказ |
| `file[attachment][][order][id]` | `integer` |     | Внутренний ID заказа |
| `file[attachment][][order][externalId]` | `string` |     | Внешний ID заказа |
| `file[attachment][][order][number]` | `string` |     | Номер заказа |
| `file[attachment][][order][site]` | `string` |     | Символьный код магазина |
| `id` | `integer` |     | ID файла |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `file` | `object (File)` | Файл |
| `file[id]` | `integer` | ID файла |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Файл не найден</p></li></ul></td></tr></tbody></table>

## Интеграция

### GET /api/v5/integration-modules/{code}

Получение интеграционного модуля

Для доступа к методу необходимо разрешение `integration_read`.

Модуль может содержать один или несколько типов конфигураций, либо может не содержать ни одного.

[Служба доставки](https://docs.retailcrm.ru/Developers/API/APIFeatures/DeliveryApi)  `delivery`

[Платежная система](https://docs.retailcrm.ru/Developers/API/APIFeatures/PaymentApi)  `payment`

[Складская система](https://docs.retailcrm.ru/Developers/API/APIFeatures/WarehouseApi)  `store`

[Телефонии](https://docs.retailcrm.ru/Developers/API/APIFeatures/TelephonyApiV4)  `telephony`

[Система мгновенного обмена сообщениями](https://docs.retailcrm.ru/Developers/API/APIFeatures/MgTransport)  `mgTransport`

[MessageGateway Бот](https://docs.retailcrm.ru/Developers/API/APIFeatures/MgBot)  `mgBot`

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `integrationModule` | `object (IntegrationModule)` | Интеграционный модуль |
| `integrationModule[code]` | `string` | Символьный код экземпляра модуля |
| `integrationModule[integrationCode]` | `string` | Символьный код модуля (должен совпадать с кодом модуля, заданным через партнерский кабинет) |
| `integrationModule[active]` | `boolean` | Статус активности |
| `integrationModule[freeze]` | `boolean` | Работа модуля заморожена |
| `integrationModule[name]` | `string` | Название (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[logo]` | `string` | Ссылка на svg логотип (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[native]` | `boolean` | Системный модуль |
| `integrationModule[baseUrl]` | `string` | Базовый URL, на который делает запросы система |
| `integrationModule[actions][]` | `array of strings` | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `activity`, `settings`) |
| `integrationModule[availableCountries]` | `array` | Массив ISO кодов стран (ISO 3166-1 alpha-2) для которых доступен модуль (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[accountUrl]` | `string` | Адрес личного кабинета (при переходе по этой ссылке отправляется `POST` запрос с параметром `clientId`) |
| `integrationModule[integrations]` | `array` | Массив конфигураций интеграций |
| `integrationModule[integrations][telephony]` | `object (Configuration)` | Конфигурация интеграции с телефонией |
| `integrationModule[integrations][telephony][makeCallUrl]` | `string` | Адрес инициации звонка |
| `integrationModule[integrations][telephony][allowEdit]` | `boolean` | Разрешить редактировать из интерфейса системы |
| `integrationModule[integrations][telephony][inputEventSupported]` | `boolean` | Поддерживает оповещения о входящем звонке |
| `integrationModule[integrations][telephony][outputEventSupported]` | `boolean` | Поддерживает оповещения о исходящем звонке |
| `integrationModule[integrations][telephony][hangupEventSupported]` | `boolean` | Поддерживает оповещения о завершении звонке |
| `integrationModule[integrations][telephony][changeUserStatusUrl]` | `string` | Уведомлять по этому адресу при смене сатуса менеджера в системе |
| `integrationModule[integrations][telephony][additionalCodes][]` | `array of objects (SerializedAdditionalCodes)` | Добавочные коды пользователей |
| `integrationModule[integrations][telephony][additionalCodes][][userId]` | `integer` | Id пользователя |
| `integrationModule[integrations][telephony][additionalCodes][][code]` | `string` | Добавочный код в телефонии |
| `integrationModule[integrations][telephony][externalPhones][]` | `array of objects (SerializedExternalPhones)` | Внешние номера |
| `integrationModule[integrations][telephony][externalPhones][][siteCode]` | `string` | Код магазина |
| `integrationModule[integrations][telephony][externalPhones][][externalPhone]` | `string` | Внешний номер |
| `integrationModule[integrations][delivery]` | `object (Configuration)` | Конфигурация интеграции со службой доставки |
| `integrationModule[integrations][delivery][description]` | `string` | Описание подключения |
| `integrationModule[integrations][delivery][actions][]` | `array of strings` | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `calculate`, `save`, `get`, `delete`, `print`, `shipmentPointList`, `tariffList`) |
| `integrationModule[integrations][delivery][payerType]` | `array` | Допустимые типы плательщиков за доставку (`receiver` - покупатель сам расплачивается напрямую со службой доставки; `sender` - магазин может брать деньги с покупателя за доставку и потом расплачивается со службой доставки) |
| `integrationModule[integrations][delivery][platePrintLimit]` | `integer` | Максимальное количество заказов при печати документов |
| `integrationModule[integrations][delivery][rateDeliveryCost]` | `boolean` | Рассчитывает ли интеграция со службой доставки стоимость самой доставки |
| `integrationModule[integrations][delivery][allowPackages]` | `boolean` | Разрешить использование упаковок |
| `integrationModule[integrations][delivery][codAvailable]` | `boolean` | Доставка наложенным платежом доступна/не доступна |
| `integrationModule[integrations][delivery][selfShipmentAvailable]` | `boolean` | Возможен самопривоз на терминал. |
| `integrationModule[integrations][delivery][duplicateOrderProductSupported]` | `boolean` | Возможность работы с заказом, содержащим несколько позиций с одинаковым торговым предложением |
| `integrationModule[integrations][delivery][allowTrackNumber]` | `boolean` | Передавать дополнительно трек номер помимо идентификатора доставки |
| `integrationModule[integrations][delivery][availableCountries]` | `array` | Список ISO кодов стран (ISO 3166-1 alpha-2) с которыми работает доставка. Если массив пустой, то нет ограничения на страны |
| `integrationModule[integrations][delivery][requiredFields]` | `array` | Список обязательных полей заказа |
| `integrationModule[integrations][delivery][statusList][]` | `array of objects (Status)` | Статусы службы доставки |
| `integrationModule[integrations][delivery][statusList][][code]` | `string` | Код статуса доставки |
| `integrationModule[integrations][delivery][statusList][][name]` | `string` | Наименование статуса |
| `integrationModule[integrations][delivery][statusList][][isEditable]` | `boolean` | Статус (`"isEditable": true`) допускает редактирование данных доставки |
| `integrationModule[integrations][delivery][statusList][][isError]` | `boolean` | Статус (`"isError": true`) сигнализирует о наличии проблем в процессе доставки. При попадании в этот статус менеджеру будет отправлено оповещение |
| `integrationModule[integrations][delivery][statusList][][isPreprocessing]` | `boolean` | Статус (`"isPreprocessing": true`) указывает, что доставка находится в процессе оформления и любые изменения с заказом не желательны. Данный флаг может быть необходим для интеграций, где оформление доставки выполняется в асинхронном режиме |
| `integrationModule[integrations][delivery][plateList][]` | `array of objects (Plate)` | Печатные формы, предоставляемых службой |
| `integrationModule[integrations][delivery][plateList][][type]` | `string` | Тип сущности для печатной формы (`order` - печатная форма для заказа (по умолчанию), `shipment` - печатная форма для отгрузки) |
| `integrationModule[integrations][delivery][plateList][][code]` | `string` | Код печатной формы |
| `integrationModule[integrations][delivery][plateList][][label]` | `string` | Наименование печатной формы |
| `integrationModule[integrations][delivery][deliveryDataFieldList][]` | `array of objects (DeliveryDataField)` | Дополнительные поля, необходимые для оформления доставки |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][code]` | `string` | Код поля |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][label]` | `string` | Имя поля |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][hint]` | `string` | Пояснение к полю |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][type]` | `string` | Тип поля. Возможны варианты (`integer` - числовое поле, `text` - текстовое поле, `autocomplete` - автокомплит поле, `checkbox`, `choice` - выпадающий список, `date` - поле с датой) |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][multiple]` | `boolean` | Указывается для типа поля `choice`. Означает что можно выбирать несколько вариантов |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][choices]` | `array` | Указывается для типа поля `choice`. Список возможных вариантов в выпадающем списке (массив объеков `{"value": "value", "label": "label"}`). Обязателен если тип поля `choice` |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][autocompleteUrl]` | `string` | Указывается для типа поля `autocomplete`. Адрес, по окторому можно получить данные для автокомплит поля. |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][visible]` | `boolean` | Отображать поле в карточке заказа |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][required]` | `boolean` | Поле обязательно для заполнения |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][affectsCost]` | `boolean` | Поле влияет на стоимость доставки. Если `"affectsCost": true` - значение поля используется в методе `calculate` |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][editable]` | `boolean` | Разрешено ли редактировать поле. Если `"editable": false` - поле информационное - заполняется только данными, полученными напрямую от службы доставки (например стоимость страховки - может заполняться после оформления доставки или при расчете стоимости) |
| `integrationModule[integrations][delivery][shipmentDataFieldList][]` | `array of objects (DeliveryDataField)` | Дополнительные поля, необходимые для оформления доставки |
| `integrationModule[integrations][delivery][settings]` | `object (Settings)` | Настройки модуля |
| `integrationModule[integrations][delivery][settings][defaultPayerType]` | `string` | Плательщик за доставку по умолчанию |
| `integrationModule[integrations][delivery][settings][costCalculateBy]` | `string` | Стоимость доставки по умолчанию (Возможные значения `auto\|manual`) |
| `integrationModule[integrations][delivery][settings][nullDeclaredValue]` | `boolean` | Нулевая объявленная стоимость по умолчанию |
| `integrationModule[integrations][delivery][settings][lockedByDefault]` | `boolean` | По умолчанию не синхронизировать со службой доставки |
| `integrationModule[integrations][delivery][settings][paymentTypes][]` | `array of objects (PaymentType)` | Способы оплаты ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-payment-types)) |
| `integrationModule[integrations][delivery][settings][paymentTypes][][code]` | `string` | Код типа платежа в системе |
| `integrationModule[integrations][delivery][settings][paymentTypes][][active]` | `boolean` | Возможность использования типа оплаты |
| `integrationModule[integrations][delivery][settings][paymentTypes][][cod]` | `boolean` | Оплата наложенным платежом |
| `integrationModule[integrations][delivery][settings][shipmentPoints][]` | `array of objects (ShipmentPoint)` | Склады ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-stores)) |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][code]` | `string` | Код склада в системе |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][shipmentPointId]` | `string` | Идентификатор терминала по умолчанию |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][shipmentPointLabel]` | `string` | Название терминала по умолчанию |
| `integrationModule[integrations][delivery][settings][statuses][]` | `array of objects (Status)` | Соответствие статусов ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-statuses)) |
| `integrationModule[integrations][delivery][settings][statuses][][code]` | `string` | Код статуса в системе |
| `integrationModule[integrations][delivery][settings][statuses][][trackingStatusCode]` | `string` | Код статуса в службе доставки |
| `integrationModule[integrations][delivery][settings][deliveryExtraData]` | `array` | Дополнительные значения полей доставки по умолчанию (`deliveryDataField.code => значение`) |
| `integrationModule[integrations][delivery][settings][shipmentExtraData]` | `array` | Дополнительные значения полей отгрузки по умолчанию (`shipmentDataField.code => значение`) |
| `integrationModule[integrations][store]` | `object (Configuration)` | Конфигурация интеграции со складской системой |
| `integrationModule[integrations][store][actions][]` | `array of objects (Action)` | Callback методы |
| `integrationModule[integrations][store][actions][][code]` | `string` | Символьный код |
| `integrationModule[integrations][store][actions][][url]` | `string` | Url метода |
| `integrationModule[integrations][store][actions][][callPoints][]` | `array of strings` | Точки вызова метода |
| `integrationModule[integrations][recommendation]` | `object (Configuration)` | Конфигурация интеграции с системой рекомендаций |
| `integrationModule[integrations][recommendation][actions][]` | `array of strings` | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `recommendation` |
| `integrationModule[integrations][recommendation][addDefaultModes]` | `boolean` | Показывать системные вкладки **Также покупают** и **Аналоги** |
| `integrationModule[integrations][recommendation][modes][]` | `array of objects (Mode)` | Массив вкладок, предоставляемых модулем |
| `integrationModule[integrations][recommendation][modes][][code]` | `string` | Код вкладки |
| `integrationModule[integrations][recommendation][modes][][names][]` | `array of strings` | \[массив\] Код языка => Название вкладки |
| `integrationModule[integrations][payment]` | `object (PaymentConfiguration)` | Конфигурация интеграции с платежной системой |
| `integrationModule[integrations][payment][actions]` | `object (Actions)` | Относительные пути от базового URL до конкретных методов |
| `integrationModule[integrations][payment][actions][create]` | `string` | Метод создания оплаты |
| `integrationModule[integrations][payment][actions][approve]` | `string` | Метод подтверждения оплаты |
| `integrationModule[integrations][payment][actions][cancel]` | `string` | Метод отмены оплаты |
| `integrationModule[integrations][payment][actions][refund]` | `string` | Метод возврата |
| `integrationModule[integrations][payment][currencies][]` | `array of strings` | Список кодов доступных валют |
| `integrationModule[integrations][payment][invoiceTypes][]` | `array of strings` | Массив поддерживаемых типов инвойсов. Возможные типы инвойсов: `link` |
| `integrationModule[integrations][payment][shops][]` | `array of objects (Shop)` | Список магазинов на стороне платежной системы |
| `integrationModule[integrations][payment][shops][][code]` | `string` | Код магазина |
| `integrationModule[integrations][payment][shops][][name]` | `string` | Название магазина |
| `integrationModule[integrations][payment][shops][][active]` | `boolean` | Статус активности |
| `integrationModule[integrations][embedJs]` | `object (NativeConfiguration)` | Конфигурация встраиваемого js api |
| `integrationModule[integrations][embedJs][entrypoint]` | `string` | Относительный url (относительно базового `integrationModule[baseUrl]`) к html-странице с js-скриптом |
| `integrationModule[integrations][embedJs][stylesheet]` | `string` | Относительный url (относительно базового `integrationModule[baseUrl]`) к файлу стилей |
| `integrationModule[integrations][embedJs][targets]` | `array` | Массив точек встраивания |
| `integrationModule[integrations][mgTransport]` | `object (TransportConfiguration)` | Конфигурация интеграции с системой мгновенного обмена сообщениями |
| `integrationModule[integrations][mgTransport][token]` | `string` | Ключ безопасности |
| `integrationModule[integrations][mgTransport][isActive]` | `boolean` | Признак активности |
| `integrationModule[integrations][mgTransport][webhookUrl]` | `string` | URL на который отправлять события |
| `integrationModule[integrations][mgTransport][actions][]` | `array of strings` | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `online`, `visits`) |
| `integrationModule[integrations][mgBot]` | `object (BotConfiguration)` | Конфигурация интеграции с MessageGateway ботом |
| `integrationModule[integrations][mgBot][isActive]` | `custom handler result for (bool)` | Признак активности |
| `integrationModule[integrations][mgBot][logo]` | `string` | Ссылка на логотип |
| `integrationModule[integrations][mgBot][token]` | `string` | Ключ безопасности |
| `integrationModule[integrations][mgBot][name]` | `string` | Название бота |

### POST /api/v5/integration-modules/{code}/edit

Создание/редактирование интеграционного модуля

Для доступа к методу необходимо разрешение `integration_write`.

Для получения сообщений об активации/деактивации и заморозке/разморозке модуля необходимо указать `baseUrl` и путь до callback `activity` - `integrationModule[actions][activity]`.

Модуль может содержать один или несколько типов конфигураций, либо может не содержать ни одного.

[Сервис рекомендаций](https://docs.retailcrm.ru/Developers/API/APIFeatures/RecommendationApi) (`recommendation`) Наличие конфигурации этого типа дает доступ к использованию методов из раздела "Рекомендации".

[Служба доставки](https://docs.retailcrm.ru/Developers/API/APIFeatures/DeliveryApi) (`delivery`) Наличие конфигурации этого типа дает доступ к использованию методов из раздела "Доставки".

[Платежная система](https://docs.retailcrm.ru/Developers/API/APIFeatures/PaymentApi) (`payment`) Наличие конфигурации этого типа дает доступ к использованию методов из раздела "Платежи".

[Складская система](https://docs.retailcrm.ru/Developers/API/APIFeatures/WarehouseApi) (`store`) Наличие конфигурации этого типа дает доступ к использованию метода `{integrationModule["integrations"]["store"]["actions"]["inventoriesUpload"]}` из раздела "Склад".

[Система мгновенного обмена сообщениями](https://docs.retailcrm.ru/Developers/API/APIFeatures/MgTransport) (`mgTransport`) Наличие конфигурации этого типа предоставляет возможность интегрировать внешние мессенджеры с системой.

[MessageGateway Бот](https://docs.retailcrm.ru/Developers/API/APIFeatures/MgBot) (`mgBot`) Наличие конфигурации этого типа предоставляет возможность интегрировать MessageGateway Ботов.

[Телефонии](https://docs.retailcrm.ru/Developers/API/APIFeatures/TelephonyApiV4) (`telephony`) Наличие конфигурации этого типа дает доступ к использованию методов из раздела "Телефония".

Поле `integrationModule[integrations][telephony][inputEventSupported]` содержит информацию поддерживает ли сервис оповещения об входящем звонке. По умолчанию значение `0`

Поле `integrationModule[integrations][telephony][outputEventSupported]` содержит информацию поддерживает ли сервис оповещения об исходящем звонке. По умолчанию значение `0`

Поле `integrationModule[integrations][telephony][hangupEventSupported]` содержит информацию поддерживает ли сервис оповещения об завершении звонка. По умолчанию значение `0`

Поле `integrationModule[integrations][telephony][additionalCodes]` содержит JSON, в котором лежит массив соответствия:_id_ пользователя и _code_ - добавочного кода в телефонии.

Поле `integrationModule[integrations][telephony][externalPhones]` содержит JSON, в котором лежит массив соответствия: _siteCode_ - кода магазина и _externalPhone_ - внешнего номера. Если для одного магазина будет задано несколько внешних номеров, при инициации звонка с заданного магазина будет выбран последний номер из списка.

Если задано поле `integrationModule[integrations][telephony][changeUserStatusUrl]`, при смене статуса менеджера в системе по заданному адресу будет отправлен `GET` запрос.

В случае успешного создания или редактирования модуля в ответе в поле `info[billingInfo]` будет содержаться информация по стоимости модуля. Если модуль не опубликован в маркетплейсе, то информация не будет передана.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `integrationModule` | `object (IntegrationModule)` |     | Интеграционный модуль |
| `integrationModule[code]` | `string` |     | Символьный код экземпляра модуля |
| `integrationModule[integrationCode]` | `string` |     | Символьный код модуля (должен совпадать с кодом модуля, заданным через партнерский кабинет) |
| `integrationModule[active]` | `boolean` |     | Статус активности |
| `integrationModule[name]` | `string` |     | Название (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[logo]` | `string` |     | Ссылка на svg логотип (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[clientId]` | `string` |     | Уникальный хеш-ключ клиента для авторизации и идентификации во внешней системе |
| `integrationModule[baseUrl]` | `string` |     | Базовый URL, на который делает запросы система |
| `integrationModule[actions][]` | `array of strings` |     | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `activity`, `settings`) |
| `integrationModule[availableCountries]` | `array` |     | Массив ISO кодов стран (ISO 3166-1 alpha-2) для которых доступен модуль (требуется, если модуль не опубликован в маркетплейсе) |
| `integrationModule[accountUrl]` | `string` |     | Адрес личного кабинета (при переходе по этой ссылке отправляется `POST` запрос с параметром `clientId`) |
| `integrationModule[integrations]` | `array` |     | Массив конфигураций интеграций |
| `integrationModule[integrations][telephony]` | `object (Configuration)` |     | Конфигурация интеграции с телефонией |
| `integrationModule[integrations][telephony][makeCallUrl]` | `string` |     | Адрес инициации звонка |
| `integrationModule[integrations][telephony][allowEdit]` | `boolean` |     | Разрешить редактировать из интерфейса системы |
| `integrationModule[integrations][telephony][inputEventSupported]` | `boolean` |     | Поддерживает оповещения о входящем звонке |
| `integrationModule[integrations][telephony][outputEventSupported]` | `boolean` |     | Поддерживает оповещения о исходящем звонке |
| `integrationModule[integrations][telephony][hangupEventSupported]` | `boolean` |     | Поддерживает оповещения о завершении звонке |
| `integrationModule[integrations][telephony][changeUserStatusUrl]` | `string` |     | Уведомлять по этому адресу при смене сатуса менеджера в системе |
| `integrationModule[integrations][telephony][additionalCodes][]` | `array of objects (SerializedAdditionalCodes)` |     | Добавочные коды пользователей |
| `integrationModule[integrations][telephony][additionalCodes][][userId]` | `integer` |     | Id пользователя |
| `integrationModule[integrations][telephony][additionalCodes][][code]` | `string` |     | Добавочный код в телефонии |
| `integrationModule[integrations][telephony][externalPhones][]` | `array of objects (SerializedExternalPhones)` |     | Внешние номера |
| `integrationModule[integrations][telephony][externalPhones][][siteCode]` | `string` |     | Код магазина |
| `integrationModule[integrations][telephony][externalPhones][][externalPhone]` | `string` |     | Внешний номер |
| `integrationModule[integrations][delivery]` | `object (Configuration)` |     | Конфигурация интеграции со службой доставки |
| `integrationModule[integrations][delivery][description]` | `string` |     | Описание подключения |
| `integrationModule[integrations][delivery][actions][]` | `array of strings` |     | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `calculate`, `save`, `get`, `delete`, `print`, `shipmentPointList`, `tariffList`) |
| `integrationModule[integrations][delivery][payerType]` | `array` |     | Допустимые типы плательщиков за доставку (`receiver` - покупатель сам расплачивается напрямую со службой доставки; `sender` - магазин может брать деньги с покупателя за доставку и потом расплачивается со службой доставки) |
| `integrationModule[integrations][delivery][platePrintLimit]` | `integer` |     | Максимальное количество заказов при печати документов |
| `integrationModule[integrations][delivery][rateDeliveryCost]` | `boolean` |     | Рассчитывает ли интеграция со службой доставки стоимость самой доставки |
| `integrationModule[integrations][delivery][allowPackages]` | `boolean` |     | Разрешить использование упаковок |
| `integrationModule[integrations][delivery][codAvailable]` | `boolean` |     | Доставка наложенным платежом доступна/не доступна |
| `integrationModule[integrations][delivery][selfShipmentAvailable]` | `boolean` |     | Возможен самопривоз на терминал. |
| `integrationModule[integrations][delivery][duplicateOrderProductSupported]` | `boolean` |     | Возможность работы с заказом, содержащим несколько позиций с одинаковым торговым предложением |
| `integrationModule[integrations][delivery][allowTrackNumber]` | `boolean` |     | Передавать дополнительно трек номер помимо идентификатора доставки |
| `integrationModule[integrations][delivery][availableCountries]` | `array` |     | Список ISO кодов стран (ISO 3166-1 alpha-2) с которыми работает доставка. Если массив пустой, то нет ограничения на страны |
| `integrationModule[integrations][delivery][requiredFields]` | `array` |     | Список обязательных полей заказа |
| `integrationModule[integrations][delivery][statusList][]` | `array of objects (Status)` |     | Статусы службы доставки |
| `integrationModule[integrations][delivery][statusList][][code]` | `string` |     | Код статуса доставки |
| `integrationModule[integrations][delivery][statusList][][name]` | `string` |     | Наименование статуса |
| `integrationModule[integrations][delivery][statusList][][isEditable]` | `boolean` |     | Статус (`"isEditable": true`) допускает редактирование данных доставки |
| `integrationModule[integrations][delivery][statusList][][isError]` | `boolean` |     | Статус (`"isError": true`) сигнализирует о наличии проблем в процессе доставки. При попадании в этот статус менеджеру будет отправлено оповещение |
| `integrationModule[integrations][delivery][statusList][][isPreprocessing]` | `boolean` |     | Статус (`"isPreprocessing": true`) указывает, что доставка находится в процессе оформления и любые изменения с заказом не желательны. Данный флаг может быть необходим для интеграций, где оформление доставки выполняется в асинхронном режиме |
| `integrationModule[integrations][delivery][plateList][]` | `array of objects (Plate)` |     | Печатные формы, предоставляемых службой |
| `integrationModule[integrations][delivery][plateList][][type]` | `string` |     | Тип сущности для печатной формы (`order` - печатная форма для заказа (по умолчанию), `shipment` - печатная форма для отгрузки) |
| `integrationModule[integrations][delivery][plateList][][code]` | `string` |     | Код печатной формы |
| `integrationModule[integrations][delivery][plateList][][label]` | `string` |     | Наименование печатной формы |
| `integrationModule[integrations][delivery][deliveryDataFieldList][]` | `array of objects (DeliveryDataField)` |     | Дополнительные поля, необходимые для оформления доставки |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][code]` | `string` |     | Код поля |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][label]` | `string` |     | Имя поля |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][hint]` | `string` |     | Пояснение к полю |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][type]` | `string` |     | Тип поля. Возможны варианты (`integer` - числовое поле, `text` - текстовое поле, `autocomplete` - автокомплит поле, `checkbox`, `choice` - выпадающий список, `date` - поле с датой) |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][multiple]` | `boolean` |     | Указывается для типа поля `choice`. Означает что можно выбирать несколько вариантов |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][choices]` | `array` |     | Указывается для типа поля `choice`. Список возможных вариантов в выпадающем списке (массив объеков `{"value": "value", "label": "label"}`). Обязателен если тип поля `choice` |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][autocompleteUrl]` | `string` |     | Указывается для типа поля `autocomplete`. Адрес, по окторому можно получить данные для автокомплит поля. |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][visible]` | `boolean` |     | Отображать поле в карточке заказа |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][required]` | `boolean` |     | Поле обязательно для заполнения |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][affectsCost]` | `boolean` |     | Поле влияет на стоимость доставки. Если `"affectsCost": true` - значение поля используется в методе `calculate` |
| `integrationModule[integrations][delivery][deliveryDataFieldList][][editable]` | `boolean` |     | Разрешено ли редактировать поле. Если `"editable": false` - поле информационное - заполняется только данными, полученными напрямую от службы доставки (например стоимость страховки - может заполняться после оформления доставки или при расчете стоимости) |
| `integrationModule[integrations][delivery][shipmentDataFieldList][]` | `array of objects (DeliveryDataField)` |     | Дополнительные поля, необходимые для оформления доставки |
| `integrationModule[integrations][delivery][settings]` | `object (Settings)` |     | Настройки модуля |
| `integrationModule[integrations][delivery][settings][defaultPayerType]` | `string` |     | Плательщик за доставку по умолчанию |
| `integrationModule[integrations][delivery][settings][costCalculateBy]` | `string` |     | Стоимость доставки по умолчанию (Возможные значения `auto\|manual`) |
| `integrationModule[integrations][delivery][settings][nullDeclaredValue]` | `boolean` |     | Нулевая объявленная стоимость по умолчанию |
| `integrationModule[integrations][delivery][settings][lockedByDefault]` | `boolean` |     | По умолчанию не синхронизировать со службой доставки |
| `integrationModule[integrations][delivery][settings][paymentTypes][]` | `array of objects (PaymentType)` |     | Способы оплаты ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-payment-types)) |
| `integrationModule[integrations][delivery][settings][paymentTypes][][code]` | `string` |     | Код типа платежа в системе |
| `integrationModule[integrations][delivery][settings][paymentTypes][][active]` | `boolean` |     | Возможность использования типа оплаты |
| `integrationModule[integrations][delivery][settings][paymentTypes][][cod]` | `boolean` |     | Оплата наложенным платежом |
| `integrationModule[integrations][delivery][settings][shipmentPoints][]` | `array of objects (ShipmentPoint)` |     | Склады ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-stores)) |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][code]` | `string` |     | Код склада в системе |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][shipmentPointId]` | `string` |     | Идентификатор терминала по умолчанию |
| `integrationModule[integrations][delivery][settings][shipmentPoints][][shipmentPointLabel]` | `string` |     | Название терминала по умолчанию |
| `integrationModule[integrations][delivery][settings][statuses][]` | `array of objects (Status)` |     | Соответствие статусов ([Справочник объектов](https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#get--api-v5-reference-statuses)) |
| `integrationModule[integrations][delivery][settings][statuses][][code]` | `string` |     | Код статуса в системе |
| `integrationModule[integrations][delivery][settings][statuses][][trackingStatusCode]` | `string` |     | Код статуса в службе доставки |
| `integrationModule[integrations][delivery][settings][deliveryExtraData]` | `array` |     | Дополнительные значения полей доставки по умолчанию (`deliveryDataField.code => значение`) |
| `integrationModule[integrations][delivery][settings][shipmentExtraData]` | `array` |     | Дополнительные значения полей отгрузки по умолчанию (`shipmentDataField.code => значение`) |
| `integrationModule[integrations][store]` | `object (Configuration)` |     | Конфигурация интеграции со складской системой |
| `integrationModule[integrations][store][actions][]` | `array of objects (Action)` |     | Callback методы |
| `integrationModule[integrations][store][actions][][code]` | `string` |     | Символьный код |
| `integrationModule[integrations][store][actions][][url]` | `string` |     | Url метода |
| `integrationModule[integrations][store][actions][][callPoints][]` | `array of strings` |     | Точки вызова метода |
| `integrationModule[integrations][recommendation]` | `object (Configuration)` |     | Конфигурация интеграции с системой рекомендаций |
| `integrationModule[integrations][recommendation][actions][]` | `array of strings` |     | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `recommendation` |
| `integrationModule[integrations][recommendation][addDefaultModes]` | `boolean` |     | Показывать системные вкладки **Также покупают** и **Аналоги** |
| `integrationModule[integrations][recommendation][modes][]` | `array of objects (Mode)` |     | Массив вкладок, предоставляемых модулем |
| `integrationModule[integrations][recommendation][modes][][code]` | `string` |     | Код вкладки |
| `integrationModule[integrations][recommendation][modes][][names][]` | `array of strings` |     | \[массив\] Код языка => Название вкладки |
| `integrationModule[integrations][payment]` | `object (PaymentConfiguration)` |     | Конфигурация интеграции с платежной системой |
| `integrationModule[integrations][payment][actions]` | `object (Actions)` |     | Относительные пути от базового URL до конкретных методов |
| `integrationModule[integrations][payment][actions][create]` | `string` |     | Метод создания оплаты |
| `integrationModule[integrations][payment][actions][approve]` | `string` |     | Метод подтверждения оплаты |
| `integrationModule[integrations][payment][actions][cancel]` | `string` |     | Метод отмены оплаты |
| `integrationModule[integrations][payment][actions][refund]` | `string` |     | Метод возврата |
| `integrationModule[integrations][payment][currencies][]` | `array of strings` |     | Список кодов доступных валют |
| `integrationModule[integrations][payment][invoiceTypes][]` | `array of strings` |     | Массив поддерживаемых типов инвойсов. Возможные типы инвойсов: `link` |
| `integrationModule[integrations][payment][shops][]` | `array of objects (Shop)` |     | Список магазинов на стороне платежной системы |
| `integrationModule[integrations][payment][shops][][code]` | `string` |     | Код магазина |
| `integrationModule[integrations][payment][shops][][name]` | `string` |     | Название магазина |
| `integrationModule[integrations][payment][shops][][active]` | `boolean` |     | Статус активности |
| `integrationModule[integrations][embedJs]` | `object (NativeConfiguration)` |     | Конфигурация встраиваемого js api |
| `integrationModule[integrations][embedJs][entrypoint]` | `string` |     | Относительный url (относительно базового `integrationModule[baseUrl]`) к html-странице с js-скриптом |
| `integrationModule[integrations][embedJs][stylesheet]` | `string` |     | Относительный url (относительно базового `integrationModule[baseUrl]`) к файлу стилей |
| `integrationModule[integrations][embedJs][targets]` | `array` |     | Массив точек встраивания |
| `integrationModule[integrations][mgTransport]` | `object (TransportConfiguration)` |     | Конфигурация интеграции с системой мгновенного обмена сообщениями |
| `integrationModule[integrations][mgTransport][webhookUrl]` | `string` |     | URL на который отправлять события |
| `integrationModule[integrations][mgTransport][actions][]` | `array of strings` |     | Относительные пути от базового URL до конкретных методов (массив `"Код метода": "Путь"`, допустимые методы: `online`, `visits`) |
| `integrationModule[integrations][mgTransport][refreshToken]` | `boolean` |     | Обновить токен |
| `integrationModule[integrations][mgBot]` | `object (BotConfiguration)` |     | Конфигурация интеграции с MessageGateway ботом |
| `integrationModule[integrations][mgBot][refreshToken]` | `custom handler result for (bool)` |     | Обновить токен |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `info` | `array` | Дополнительная информация о результатах редактирования модуля |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Интеграционный модуль успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>402</code></p></td><td colspan="1" rowspan="1"><ul><li><p>На счете клиента недостаточно средств для активации интеграционного модуля</p></li></ul></td></tr></tbody></table>

### POST /api/v5/integration-modules/{code}/update-scopes

Обновление разрешений для API ключа

Для доступа к методу необходимо разрешение `integration_write`.

Метод позволяет получить новый API ключ с разрешениями, которые были переданы в запросе, для интеграционного модуля.

Данный метод доступен для модулей с поддержкой простого подключения и может также быть использован для обновления API ключей модулями, которые были подключены устаревшим способом с созданием API ключей в интерфейсе системы.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `requires` | `object (Requires)` |     |     |
| `requires[scopes][]` | `array of strings` |     | Разрешения, необходимые API ключу для работы модуля |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `apiKey` | `string` | Новый API ключ для интеграционного модуля |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### CallbackGET {configUrl}

Получение данных для подключения модуля

Callback для получения конфигурации подключения. Вызывается перед подключением модуля.

`{configUrl}` - URL для запроса конфигурации простого подключения. Указывается в партнерском кабинете, при регистрации модуля

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `scopes[]` | `array of strings` | Разрешения, необходимые API ключу для работы модуля |
| `registerUrl` | `string` | URL для регистрации модуля |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["actions"\]\["activity"\]}

Оповещение об изменении статуса активности или заморозки модуля

Callback вызывается при изменении статуса активности и заморозки модуля или при переименовании системы. В случае деактивации работа модуля должна быть прекращена. При заморозке - работа модуля должна быть приостановлена. При переименовании системы нужно обновить URL системы, на который отправляются запросы. Новый URL приходит с нового адреса системы, для определения аккаунта необходимо использовать `clientId`. При каждой инициации этого метода передаются все параметры.

Если модуль опубликован в маркетплейсе, то в поле `billingInfo` будет содержаться информация по стоимости модуля.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `activity` | `object (IntegrationModule)` |     | Интеграционный модуль |
| `activity[active]` | `boolean` |     | Статус активности |
| `activity[freeze]` | `boolean` |     | Работа модуля заморожена |
| `systemUrl` | `string` |     | Актуальный URL системы, где активирован интеграционный модуль (пример: `https://demo.retailcrm.ru` ) |
| `billingInfo` | `object (IntegrationModuleBillingInfo)` |     | Информация о стоимости модуля |
| `billingInfo[price]` | `float` |     | Стоимость модуля |
| `billingInfo[priceWithDiscount]` | `float` |     | Стоимость модуля со скидкой при её наличии |
| `billingInfo[currency]` | `object (IntegrationModuleBillingInfoCurrency)` |     | Валюта |
| `billingInfo[currency][name]` | `string` |     | Название валюты |
| `billingInfo[currency][shortName]` | `string` |     | Название валюты в сокращённом виде |
| `billingInfo[currency][code]` | `string` |     | Код валюты |
| `billingInfo[billingType]` | `string` |     | Тип оплаты (`fixed` - за модуль, `byChannel` - за канал) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["actions"\]\["settings"\]}

Оповещение об изменении настроек системы

Callback вызывается при изменении следующих настроек системы:

- нерабочие дни
- рабочее время
- валюта по умолчанию
- языка системы
- часовой пояса системы
- настройки чатов

В запросе всегда передаются значения всех указанных настроек.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `settings` | `object (Settings)` |     | Настройки системы |
| `settings[default_currency]` | `object (Value)` |     | `deprecated` Валюта по умолчанию |
| `settings[default_currency][value]` | `string` |     | Значение настройки |
| `settings[default_currency][updated_at]` | `DateTime` | `Y-m-d H:i:s` | Время последнего изменения настройки |
| `settings[system_language]` | `object (Value)` |     | Язык системы |
| `settings[system_language][value]` | `string` |     | Значение настройки |
| `settings[system_language][updated_at]` | `DateTime` | `Y-m-d H:i:s` | Время последнего изменения настройки |
| `settings[timezone]` | `object (Value)` |     | Временная зона |
| `settings[timezone][value]` | `string` |     | Значение настройки |
| `settings[timezone][updated_at]` | `DateTime` | `Y-m-d H:i:s` | Время последнего изменения настройки |
| `settings[work_times][]` | `array of objects (WorkTime)` |     | Рабочее время |
| `settings[work_times][][day_type]` | `string` |     | День недели |
| `settings[work_times][][start_time]` | `string` |     | Начало рабочего времени |
| `settings[work_times][][end_time]` | `string` |     | Конец рабочего времени |
| `settings[work_times][][lunch_start_time]` | `string` |     | Время начала перерыва |
| `settings[work_times][][lunch_end_time]` | `string` |     | Время конца перерыва |
| `settings[non_working_days][]` | `array of objects (NonWorkingDay)` |     | Нерабочие дни |
| `settings[non_working_days][][start_date]` | `DateTime` | `m.d` | Начало нерабочих дней |
| `settings[non_working_days][][end_date]` | `DateTime` | `m.d` | Конец нерабочих дней |
| `settings[mg]` | `object (IntegrationData)` |     | Настройки чатов |
| `settings[mg][order_creation]` | `object (OrderCreationSettings)` |     | Параметры, которые будут автоматически указываться в заказе при оформлении из чатов |
| `settings[mg][order_creation][default]` | `object (ChannelSetting)` |     | Параметры по-умолчанию |
| `settings[mg][order_creation][default][site]` | `string` |     | Магазин |
| `settings[mg][order_creation][default][order_type]` | `string` |     | Тип заказа |
| `settings[mg][order_creation][default][order_method]` | `string` |     | Метод оформления заказа |
| `settings[mg][order_creation][channels][]` | `array of objects (ChannelSetting)` |     | Параметры для отдельных каналов (ключ - externalId канала) |
| `settings[mg][order_creation][channels][][site]` | `string` |     | Магазин |
| `settings[mg][order_creation][channels][][order_type]` | `string` |     | Тип заказа |
| `settings[mg][order_creation][channels][][order_method]` | `string` |     | Метод оформления заказа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### CallbackPOST {registerUrl}

Регистрация модуля

Callback вызывается при подключении модуля.

`{registerUrl}` - это URL, по которому будет отправлен запрос на подключение модуля. Подробнее в документации по callback методу [{configUrl}](https://docs.retailcrm.ru/api/ru/RetailCRM/apiMethods/v5#callback_get--configUrl).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `register` | `object (Register)` |     |     |
| `register[token]` | `string` |     | API-ключ в виде хэш-кода, сгенерированного на основе секретного токена с помощью алгоритма sha256 методом hmac для проверки подлинности запроса |
| `register[systemUrl]` | `string` |     | Технический домен системы, на который необходимо отправлять запросы |
| `register[apiKey]` | `string` |     | API-ключ для обращения к API системы |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `accountUrl` | `string` | Адрес личного кабинета (при переходе по этой ссылке отправляется `POST` запрос с параметром `clientId`) |

## Лояльность

### POST /api/v5/loyalty/account/create

Добавление клиента в программу лояльности

Для доступа к методу необходимо разрешение `loyalty_write`.

Метод проверяет возможность участия клиента в программе лояльности и добавляет его

В настройках к ПЛ возможно указать список полей обязательных для заполнения в Клиенте и в Участии. Если не все указанные поля заполнены соответствующее сообщение выводится в ответе, в поле `warnings`. Если настройка обязательных полей и требование подтверждения активации по смс отсутствует или переданы все необходимые поля то участие создается активным. В обратном случае оно создается неактивным. Неактивному Участию недоступно списание бонусов и применение скидочных привилегий.

Также должно быть заполнено одно из полей `phoneNumber` или `cardNumber`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `loyaltyAccount` | `object (SerializedCreateLoyaltyAccount)` |     |     |
| `loyaltyAccount[phoneNumber]` | `string` |     | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` |     | Номер карты |
| `loyaltyAccount[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `loyaltyAccount[customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `loyaltyAccount[customer][id]` | `integer` |     | Внутренний ID клиента |
| `loyaltyAccount[customer][externalId]` | `string` |     | Внешний ID клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyaltyAccount` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `loyaltyAccount[active]` | `boolean` | Признак активности участия |
| `loyaltyAccount[id]` | `integer` | ID участия |
| `loyaltyAccount[phoneNumber]` | `string` | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` | Номер карты |
| `loyaltyAccount[amount]` | `float` | Количество активных бонусов |
| `loyaltyAccount[level]` | `object (LoyaltyLevel)` | Уровень участия |
| `loyaltyAccount[level][id]` | `integer` | ID уровня |
| `loyaltyAccount[level][name]` | `string` | Название уровня |
| `loyaltyAccount[createdAt]` | `DateTime` | Дата создания |
| `loyaltyAccount[activatedAt]` | `DateTime` | Дата активации участия |
| `loyaltyAccount[confirmedPhoneAt]` | `DateTime` | Дата верификации номера телефона |
| `loyaltyAccount[lastCheckId]` | `string` | ID последней СМС-верификации |
| `loyaltyAccount[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `warnings` | `array` |     |

### GET /api/v5/loyalty/account/{id}

Получение информации об участии в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | Id участия в программе лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyaltyAccount` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `loyaltyAccount[active]` | `boolean` | Признак активности участия |
| `loyaltyAccount[id]` | `integer` | ID участия |
| `loyaltyAccount[loyalty]` | `object (Loyalty)` | Программа лояльности |
| `loyaltyAccount[loyalty][currency]` | `string` | Валюта |
| `loyaltyAccount[loyalty][id]` | `integer` | ID программы лояльности |
| `loyaltyAccount[loyalty][minResidualCost]` | `float` | Минимальная остаточная стоимость товара/услуги (в валюте объекта) |
| `loyaltyAccount[customer]` | `object (Customer)` | Клиент |
| `loyaltyAccount[customer][id]` | `integer` | ID клиента |
| `loyaltyAccount[customer][externalId]` | `string` | Внешний ID клиента |
| `loyaltyAccount[customer][site]` | `string` | Магазин, с которого пришел клиент |
| `loyaltyAccount[customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `loyaltyAccount[customer][firstName]` | `string` | Имя |
| `loyaltyAccount[customer][lastName]` | `string` | Фамилия |
| `loyaltyAccount[customer][patronymic]` | `string` | Отчество |
| `loyaltyAccount[phoneNumber]` | `string` | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` | Номер карты |
| `loyaltyAccount[amount]` | `float` | Количество активных бонусов |
| `loyaltyAccount[ordersSum]` | `float` | Сумма покупок (в валюте объекта) |
| `loyaltyAccount[nextLevelSum]` | `float` | Необходимая сумма покупок для перехода на след уровень |
| `loyaltyAccount[level]` | `object (LoyaltyLevel)` | Уровень участия |
| `loyaltyAccount[level][type]` | `string` | Тип уровня. Возможные значения: `bonus_converting`, `bonus_percent`, `discount` |
| `loyaltyAccount[level][id]` | `integer` | ID уровня |
| `loyaltyAccount[level][name]` | `string` | Название уровня |
| `loyaltyAccount[level][sum]` | `custom handler result for (int)` | Сумма, необходимая для перехода на данный уровень (в валюте объекта) |
| `loyaltyAccount[level][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyaltyAccount[level][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyaltyAccount[level][creditConditions][]` | `array of objects (LoyaltyLevelCondition)` | Условия начисления бунусов на отдельные товары |
| `loyaltyAccount[level][creditConditions][][id]` | `integer` | ID условия |
| `loyaltyAccount[level][creditConditions][][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyaltyAccount[level][creditConditions][][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyaltyAccount[level][creditConditions][][items]` | `array of objects (LoyaltyLevelConditionItemOffer)array of objects (LoyaltyLevelConditionItemProduct)array of objects (LoyaltyLevelConditionItemProductGroup)array of objects (LoyaltyLevelConditionItemManufacturer)` | Элементы условия |
| `loyaltyAccount[level][creditConditions][][items][id]` | `integer` | ID элемента условия |
| `loyaltyAccount[level][creditConditions][][items][offer]` | `object (Offer)` | Торговое предложение (SKU) |
| `loyaltyAccount[level][creditConditions][][items][offer][displayName]` | `string` | Название SKU |
| `loyaltyAccount[level][creditConditions][][items][offer][id]` | `integer` | ID торгового предложения |
| `loyaltyAccount[level][creditConditions][][items][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `loyaltyAccount[level][creditConditions][][items][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `loyaltyAccount[level][creditConditions][][items][offer][name]` | `string` | Название |
| `loyaltyAccount[level][creditConditions][][items][offer][article]` | `string` | Артикул |
| `loyaltyAccount[level][creditConditions][][items][offer][active]` | `boolean` | Активность |
| `loyaltyAccount[level][creditConditions][][items][offer][properties][]` | `array of strings` | Свойства SKU |
| `loyaltyAccount[createdAt]` | `DateTime` | Дата создания |
| `loyaltyAccount[activatedAt]` | `DateTime` | Дата активации участия |
| `loyaltyAccount[confirmedPhoneAt]` | `DateTime` | Дата верификации номера телефона |
| `loyaltyAccount[lastCheckId]` | `string` | ID последней СМС-верификации |
| `loyaltyAccount[status]` | `string` | Статус участия. Возможные значения: `not_confirmed`, `activated`, `deactivated` |
| `loyaltyAccount[customFields]` | `array` | Ассоциативный массив пользовательских полей |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/loyalty/account/{id}/activate

Активация участия в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_write`.

Метод позволяет активировать созданное участие. Если включена настройка регистрации в программе лояльности "Подтверждение регистрации по SMS", то необходимо, чтобы в участии программы лояльности был указан номер телефона. На него будет выслано смс сообщение с подтверждением активации участия в программе. Активация будет произведена только после подтверждения активации кодом из смс.

Для повторной отправки смс следует вызвать этот метод еще раз. Повторная отправка доступна через 60 секунд. Срок жизни кода из смс - 5 минут.

В случае, если отправка смс для активации не требуется, активация будет произведена согласно настройкам программы лояльности, и в ответе вернется только объект `LoyaltyAccount`. В случае подтверждения по смс будет также возвращен объект `SmsVerification`.

Код, полученный по смс, необходимо отправить в методе подтверждения верификации, указав параметр `checkId`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | Id участия в программе лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyaltyAccount` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `loyaltyAccount[active]` | `boolean` | Признак активности участия |
| `loyaltyAccount[id]` | `integer` | ID участия |
| `loyaltyAccount[phoneNumber]` | `string` | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` | Номер карты |
| `loyaltyAccount[amount]` | `float` | Количество активных бонусов |
| `loyaltyAccount[level]` | `object (LoyaltyLevel)` | Уровень участия |
| `loyaltyAccount[level][id]` | `integer` | ID уровня |
| `loyaltyAccount[level][name]` | `string` | Название уровня |
| `loyaltyAccount[createdAt]` | `DateTime` | Дата создания |
| `loyaltyAccount[activatedAt]` | `DateTime` | Дата активации участия |
| `loyaltyAccount[confirmedPhoneAt]` | `DateTime` | Дата верификации номера телефона |
| `loyaltyAccount[lastCheckId]` | `string` | ID последней СМС-верификации |
| `loyaltyAccount[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `verification` | `object (SmsVerification)` | SMS-верификация |
| `verification[createdAt]` | `DateTime` | Дата создания (Y-m-d H:i:s) |
| `verification[expiredAt]` | `DateTime` | Дата окончания срока жизни (Y-m-d H:i:s) |
| `verification[verifiedAt]` | `DateTime` | Дата успешной верификации (Y-m-d H:i:s) |
| `verification[checkId]` | `string` | Идентификатор проверки кода |
| `verification[actionType]` | `string` | Тип действия |

### POST /api/v5/loyalty/account/{id}/bonus/charge

Списание бонусов участию в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_write`.

Метод позволяет списать бонусы участию в программе лояльности.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `amount` | `float` |     | Количество бонусов к списанию |
| `comment` | `string` |     | Комментарий |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### POST /api/v5/loyalty/account/{id}/bonus/credit

Начисление бонусов участию в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_write`.

Метод позволяет начислить бонусы участию в программе лояльности.

Если не передана дата активации бонусов `activationDate`, активация бонусов произойдет в соответствии с настройками программы лояльности

Если не передана дата сгорания бонусов `expireDate`, сгорание бонусов произойдет в соответствии с настройками программы лояльности

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `amount` | `float` |     | Количество бонусов к начислению |
| `activationDate` | `DateTime` | `Y-m-d` | Дата активации бонусов |
| `expireDate` | `DateTime` | `Y-m-d` | Дата сгорания бонусов |
| `comment` | `string` |     | Комментарий |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyaltyBonus` | `object (LoyaltyBonus)` |     |
| `loyaltyBonus[amount]` | `float` | Количество начисленных бонусов |
| `loyaltyBonus[activationDate]` | `DateTime` | Дата активации бонусов |
| `loyaltyBonus[expireDate]` | `DateTime` | Дата сгорания бонусов |

### GET /api/v5/loyalty/account/{id}/bonus/operations

История бонусного счета для конкретного участия

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод позволяет получить историю бонусного счета для участия в программе лояльности.

Поле `bonusOperations[][type]` содержит тип действия, которое привело к изменению бонусного счета. Возможные значения:

- `credit_for_order` - начисление за заказ
- `burn` - сгорание
- `credit_for_event` - начисление за событие
- `charge_for_order` - списание за заказ
- `charge_manual` - ручное списание
- `credit_manual` - ручное начисление
- `cancel_of_charge` - отмена списания
- `cancel_of_credit` - отмена начисления

Поле `bonusOperations[][amount]` содержит количество списанных, сгоревших, либо начисленных бонусов. Если бонусы были начислены, значение будет положительным, если списаны или сгорели - отрицательным.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (LoyaltyAccountBonusOperationsApiFilterType)` |     |     |
| `filter[createdAtFrom]` | `string` |     | Дата создания (от) |
| `filter[createdAtTo]` | `string` |     | Дата создания (до) |
| `id` | `integer` |     | Id участия в программе лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `bonusOperations[]` | `array of objects (Operation)` | Запись в истории бонусного счета |
| `bonusOperations[][type]` | `string` | Тип действия |
| `bonusOperations[][createdAt]` | `DateTime` | Дата действия |
| `bonusOperations[][amount]` | `float` | Количество бонусов |
| `bonusOperations[][order]` | `object (OperationOrder)` | Связанный заказ |
| `bonusOperations[][order][id]` | `integer` | ID заказа |
| `bonusOperations[][order][externalId]` | `string` | Внешний ID заказа |
| `bonusOperations[][bonus]` | `object (OperationBonus)` | Начисленные бонусы |
| `bonusOperations[][bonus][activationDate]` | `DateTime` | Дата активации бонусов |
| `bonusOperations[][bonus][expireDate]` | `DateTime` | Дата сгорания бонусов |
| `bonusOperations[][event]` | `object (OperationEvent)` | Событие программы лояльности |
| `bonusOperations[][event][id]` | `integer` | ID события |
| `bonusOperations[][event][type]` | `string` | Тип события. Возможные значения: `birthday`, `welcome` |
| `bonusOperations[][comment]` | `string` | Комментарий |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/loyalty/account/{id}/bonus/{status}/details

Получение детализации по бонусному счету

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод позволяет получить детальную информацию о бонусах в статусах ожидает активации `waiting_activation` и `burn_soon` скоро сгорят.

Параметр `filter[date]` фильтрует в зависимости от переданного статуса

- Если статус `waiting_activation` - неактивные бонусы с датой активации до даты переданной в фильтре включительно
- Если статус `burn_soon` - бонусы которые сгорят до даты переданной в фильтре включительно

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (LoyaltyAccountBonusApiFilterType)` |     |     |
| `filter[date]` | `DateTime` |     |     |
| `id` | `integer` |     | Id участия в программе лояльности |
| `status` | `string` |     | Статус бонусов |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `statistic` | `object (LoyaltyBonusStatisticResponse)` | Статистика по бонусам |
| `statistic[totalAmount]` | `float` | Общее количество бонусов |
| `bonuses[]` | `array of objects (BonusDetail)` |     |
| `bonuses[][date]` | `DateTime` | Дата сгорания или активации бонусов |
| `bonuses[][amount]` | `float` | Количество бонусов |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/loyalty/account/{id}/edit

Редактирование участия в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_write`.

Метод редактирования участия в программе лояльности.

При попытке изменить верифицированный номер телефона будет возвращена ошибка, доступно только в интерфейсе, в карточке участия.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `loyaltyAccount` | `object (SerializedEditLoyaltyAccount)` |     |     |
| `loyaltyAccount[phoneNumber]` | `string` |     | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` |     | Номер карты |
| `loyaltyAccount[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `loyaltyAccount[loyaltyLevelId]` | `integer` |     | Идентификатор уровня программы лояльности |
| `id` | `integer` |     | Id участия в программе лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyaltyAccount` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `loyaltyAccount[active]` | `boolean` | Признак активности участия |
| `loyaltyAccount[id]` | `integer` | ID участия |
| `loyaltyAccount[phoneNumber]` | `string` | Номер телефона |
| `loyaltyAccount[cardNumber]` | `string` | Номер карты |
| `loyaltyAccount[amount]` | `float` | Количество активных бонусов |
| `loyaltyAccount[level]` | `object (LoyaltyLevel)` | Уровень участия |
| `loyaltyAccount[level][id]` | `integer` | ID уровня |
| `loyaltyAccount[level][name]` | `string` | Название уровня |
| `loyaltyAccount[createdAt]` | `DateTime` | Дата создания |
| `loyaltyAccount[activatedAt]` | `DateTime` | Дата активации участия |
| `loyaltyAccount[confirmedPhoneAt]` | `DateTime` | Дата верификации номера телефона |
| `loyaltyAccount[lastCheckId]` | `string` | ID последней СМС-верификации |
| `loyaltyAccount[customFields]` | `array` | Ассоциативный массив пользовательских полей |

### GET /api/v5/loyalty/accounts

Список участий в программе лояльности

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод возвращает список участий в программе лояльности.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (LoyaltyAccountFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID участий в программе лояльности |
| `filter[id]` | `integer` |     | ID участия |
| `filter[customer]` | `string` | `{length: {max: 255}}` | Клиент |
| `filter[loyalties][]` | `array of integers` |     | Массив ID Программ лояльности |
| `filter[sites][]` | `array of strings` |     | Магазины программы лояльности |
| `filter[status]` | `string` | `[activated\|deactivated\|not_confirmed]` | Статус |
| `filter[phoneNumber]` | `string` | `{length: {max: 255}}` | Номер телефона |
| `filter[cardNumber]` | `string` | `{length: {max: 255}}` | Номер карты |
| `filter[level]` | `integer` |     | Внутренний ID уровня |
| `filter[customerId]` | `integer` | `{range: {>=0, <=100000000000}}` | Внутренний ID клиента |
| `filter[customerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID клиента |
| `filter[customerSites][]` | `array of strings` |     | Магазины клиента |
| `filter[createdAtFrom]` | `DateTime` | `Y-m-d` | Дата регистрации (от) |
| `filter[createdAtTo]` | `DateTime` | `Y-m-d` | Дата регистрации (до) |
| `filter[burnDateFrom]` | `DateTime` | `Y-m-d` | Дата сгорания бонусов (от) |
| `filter[burnDateTo]` | `DateTime` | `Y-m-d` | Дата сгорания бонусов (до) |
| `filter[minOrdersSum]` | `integer` |     | Сумма покупок (от) |
| `filter[maxOrdersSum]` | `integer` |     | Сумма покупок (до) |
| `filter[minAmount]` | `integer` |     | Баланс бонусов (от) |
| `filter[maxAmount]` | `integer` |     | Баланс бонусов (до) |
| `filter[customFields]` | `array` |     | Пользовательские поля |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `loyaltyAccounts[]` | `array of objects (LoyaltyAccount)` | Участие в программе лояльности |
| `loyaltyAccounts[][active]` | `boolean` | Признак активности участия |
| `loyaltyAccounts[][id]` | `integer` | ID участия |
| `loyaltyAccounts[][loyalty]` | `object (Loyalty)` | Программа лояльности |
| `loyaltyAccounts[][loyalty][currency]` | `string` | Валюта |
| `loyaltyAccounts[][loyalty][id]` | `integer` | ID программы лояльности |
| `loyaltyAccounts[][loyalty][minResidualCost]` | `float` | Минимальная остаточная стоимость товара/услуги (в валюте объекта) |
| `loyaltyAccounts[][customer]` | `object (Customer)` | Клиент |
| `loyaltyAccounts[][customer][id]` | `integer` | ID клиента |
| `loyaltyAccounts[][customer][externalId]` | `string` | Внешний ID клиента |
| `loyaltyAccounts[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `loyaltyAccounts[][customer][firstName]` | `string` | Имя |
| `loyaltyAccounts[][customer][lastName]` | `string` | Фамилия |
| `loyaltyAccounts[][customer][patronymic]` | `string` | Отчество |
| `loyaltyAccounts[][phoneNumber]` | `string` | Номер телефона |
| `loyaltyAccounts[][cardNumber]` | `string` | Номер карты |
| `loyaltyAccounts[][amount]` | `float` | Количество активных бонусов |
| `loyaltyAccounts[][ordersSum]` | `float` | Сумма покупок (в валюте объекта) |
| `loyaltyAccounts[][nextLevelSum]` | `float` | Необходимая сумма покупок для перехода на след уровень |
| `loyaltyAccounts[][level]` | `object (LoyaltyLevel)` | Уровень участия |
| `loyaltyAccounts[][level][type]` | `string` | Тип уровня. Возможные значения: `bonus_converting`, `bonus_percent`, `discount` |
| `loyaltyAccounts[][level][id]` | `integer` | ID уровня |
| `loyaltyAccounts[][level][name]` | `string` | Название уровня |
| `loyaltyAccounts[][level][sum]` | `custom handler result for (int)` | Сумма, необходимая для перехода на данный уровень (в валюте объекта) |
| `loyaltyAccounts[][level][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyaltyAccounts[][level][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyaltyAccounts[][createdAt]` | `DateTime` | Дата создания |
| `loyaltyAccounts[][activatedAt]` | `DateTime` | Дата активации участия |
| `loyaltyAccounts[][confirmedPhoneAt]` | `DateTime` | Дата верификации номера телефона |
| `loyaltyAccounts[][lastCheckId]` | `string` | ID последней СМС-верификации |
| `loyaltyAccounts[][status]` | `string` | Статус участия. Возможные значения: `not_confirmed`, `activated`, `deactivated` |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/loyalty/bonus/operations

История бонусного счета для всех участий

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод позволяет получить историю бонусного счета для всех участий во всех программах лояльности

Поле `bonusOperations[][type]` содержит тип действия, которое привело к изменению бонусного счета. Возможные значения:

- `credit_for_order` - начисление за заказ
- `burn` - сгорание
- `credit_for_event` - начисление за событие
- `charge_for_order` - списание за заказ
- `charge_manual` - ручное списание
- `credit_manual` - ручное начисление
- `cancel_of_charge` - отмена списания
- `cancel_of_credit` - отмена начисления

Поле `bonusOperations[][amount]` содержит количество списанных, сгоревших, либо начисленных бонусов. Если бонусы были начислены, значение будет положительным, если списаны или сгорели - отрицательным.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `cursor` | `string` |     | Курсор элемента с которого начинается поиск |
| `filter` | `object (LoyaltyBonusOperationsApiFilterType)` |     |     |
| `filter[loyalties][]` | `array of integers` |     | Массив ID Программ лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (CursorPaginationResponse)` | Пагинация с курсорами |
| `pagination[nextCursor]` | `string` | Курсор первого элемента на следующей странице |
| `bonusOperations[]` | `array of objects (Operation)` | Запись в истории бонусного счета |
| `bonusOperations[][type]` | `string` | Тип действия |
| `bonusOperations[][createdAt]` | `DateTime` | Дата действия |
| `bonusOperations[][amount]` | `float` | Количество бонусов |
| `bonusOperations[][order]` | `object (OperationOrder)` | Связанный заказ |
| `bonusOperations[][order][id]` | `integer` | ID заказа |
| `bonusOperations[][order][externalId]` | `string` | Внешний ID заказа |
| `bonusOperations[][bonus]` | `object (OperationBonus)` | Начисленные бонусы |
| `bonusOperations[][bonus][activationDate]` | `DateTime` | Дата активации бонусов |
| `bonusOperations[][bonus][expireDate]` | `DateTime` | Дата сгорания бонусов |
| `bonusOperations[][event]` | `object (OperationEvent)` | Событие программы лояльности |
| `bonusOperations[][event][id]` | `integer` | ID события |
| `bonusOperations[][event][type]` | `string` | Тип события. Возможные значения: `birthday`, `welcome` |
| `bonusOperations[][loyaltyAccount]` | `object (OperationLoyaltyAccount)` | Связанное участие |
| `bonusOperations[][loyaltyAccount][id]` | `integer` | ID участия |
| `bonusOperations[][loyalty]` | `object (OperationLoyalty)` | Связанная программа лояльности |
| `bonusOperations[][loyalty][id]` | `integer` | ID программы лояльности |
| `bonusOperations[][comment]` | `string` | Комментарий |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/loyalty/calculate

Расчёт максимальной скидки

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод рассчитывает максимальную скидку для клиента. Учитывая персональную скидку или уровень лояльности или событие. В методе есть возможность передать расчетное кол-во бонусов для списания (только для расчетов), в параметре `bonuses`, по умолчанию 0.

Поле `privilegeType` может содержать одно из следующих значений:

- `personal_discount` - персональная скидка клиента;
- `loyalty_level` - расчет скидки или начисление бонусов исходя из настроек уровня ПЛ клиента;
- `loyalty_event` - расчет скидки по событию ПЛ;
- `none` - не применять скидки ПЛ к заказу;

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `order` | `object (SerializedOrder)` |     |     |
| `order[privilegeType]` | `string` |     | Тип привилегии |
| `order[discountManualAmount]` | `double` |     | Денежная скидка на весь заказ (в валюте объекта) |
| `order[discountManualPercent]` | `double` |     | Процентная скидка на весь заказ |
| `order[customer]` | `object (SerializedRelationCustomer)` |     | Клиент |
| `order[customer][id]` | `integer` |     | Внутренний ID клиента |
| `order[customer][externalId]` | `string` |     | Внешний ID клиента |
| `order[items][]` | `array of objects (SerializedOrderProduct)` |     |     |
| `order[items][][initialPrice]` | `double` |     | Цена товара/SKU (в валюте объекта) |
| `order[items][][discountManualAmount]` | `double` |     | Денежная скидка на единицу товара (в валюте объекта) |
| `order[items][][discountManualPercent]` | `double` |     | Процентная скидка на единицу товара |
| `order[items][][quantity]` | `float` |     | Количество |
| `order[items][][offer]` | `object (SerializedOrderProductOffer)` |     | Торговое предложение |
| `order[items][][offer][id]` | `integer` |     | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `order[items][][offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `order[items][][priceType]` | `object (PriceType)` |     | Тип цены |
| `order[items][][priceType][code]` | `string` |     | Код типа цены |
| `order[delivery]` | `object (SerializedOrderDelivery)` |     | Данные о доставке |
| `order[delivery][cost]` | `double` |     | Стоимость доставки |
| `order[applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |
| `bonuses` | `float` |     | Количество бонусов для списания |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `order` | `object (SerializedLoyaltyOrder)` |     |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[currency]` | `string` | Валюта |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyAccount]` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `order[loyaltyAccount][id]` | `integer` | ID участия |
| `order[loyaltyAccount][amount]` | `float` | Количество активных бонусов |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[customer]` | `object (Customer)` | Клиент |
| `order[customer][id]` | `integer` | ID клиента |
| `order[customer][externalId]` | `string` | Внешний ID клиента |
| `order[customer][personalDiscount]` | `double` | Персональная скидка |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[site]` | `string` | Магазин |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `calculations[]` | `array of objects (LoyaltyCalculation)` |     |
| `calculations[][privilegeType]` | `string` | Тип привилегии |
| `calculations[][discount]` | `float` | Денежная скидка на заказ с учетом списанных бонусов по курсу, заданному в настройках |
| `calculations[][creditBonuses]` | `float` | Бонусы к начислению |
| `calculations[][loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `calculations[][loyaltyEventDiscount][id]` | `integer` | ID  |
| `calculations[][maxChargeBonuses]` | `float` | Бонусы, доступные для списания |
| `calculations[][maximum]` | `boolean` | Привилегия с максимальной выгодой |
| `loyalty` | `object (SerializedLoyalty)` |     |
| `loyalty[currency]` | `string` | Валюта |
| `loyalty[name]` | `string` | Название программы лояльности |
| `loyalty[chargeRate]` | `float` | Курс при списании бонусов (в валюте объекта) |

### GET /api/v5/loyalty/loyalties

Список программ лояльности

Для доступа к методу необходимо разрешение `loyalty_read`.

Метод возвращает список программ лояльности

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (LoyaltyApiFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID программ лояльности |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[active]` | `boolean` |     | Активна |
| `filter[blocked]` | `boolean` |     | Заблокирована |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `loyalties[]` | `array of objects (Loyalty)` | Программа лояльности |
| `loyalties[][levels][]` | `array of objects (LoyaltyLevel)` | Уровни программы лояльности |
| `loyalties[][levels][][type]` | `string` | Тип уровня. Возможные значения: `bonus_converting`, `bonus_percent`, `discount` |
| `loyalties[][levels][][id]` | `integer` | ID уровня |
| `loyalties[][levels][][name]` | `string` | Название уровня |
| `loyalties[][levels][][sum]` | `custom handler result for (int)` | Сумма, необходимая для перехода на данный уровень (в валюте объекта) |
| `loyalties[][levels][][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyalties[][levels][][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyalties[][active]` | `boolean` | Активна |
| `loyalties[][blocked]` | `boolean` | Заблокирована |
| `loyalties[][currency]` | `string` | Валюта |
| `loyalties[][id]` | `integer` | ID программы лояльности |
| `loyalties[][name]` | `string` | Название программы лояльности |
| `loyalties[][confirmSmsCharge]` | `boolean` | Подтверждать списание по СМС |
| `loyalties[][confirmSmsRegistration]` | `boolean` | Подтверждать участие по СМС |
| `loyalties[][minResidualCost]` | `float` | Минимальная остаточная стоимость товара/услуги (в валюте объекта) |
| `loyalties[][createdAt]` | `DateTime` | Дата создания |
| `loyalties[][activatedAt]` | `DateTime` | Дата запуска |
| `loyalties[][deactivatedAt]` | `DateTime` | Дата остановки |
| `loyalties[][blockedAt]` | `DateTime` | Дата блокировки |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/loyalty/loyalties/{id}

Получение информации о программе лояльности

Для доступа к методу необходимо разрешение `loyalty_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID программы лояльности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `loyalty` | `object (Loyalty)` | Программа лояльности |
| `loyalty[levels][]` | `array of objects (LoyaltyLevel)` | Уровни программы лояльности |
| `loyalty[levels][][type]` | `string` | Тип уровня. Возможные значения: `bonus_converting`, `bonus_percent`, `discount` |
| `loyalty[levels][][id]` | `integer` | ID уровня |
| `loyalty[levels][][name]` | `string` | Название уровня |
| `loyalty[levels][][sum]` | `custom handler result for (int)` | Сумма, необходимая для перехода на данный уровень (в валюте объекта) |
| `loyalty[levels][][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyalty[levels][][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyalty[levels][][creditConditions][]` | `array of objects (LoyaltyLevelCondition)` | Условия начисления бунусов на отдельные товары |
| `loyalty[levels][][creditConditions][][id]` | `integer` | ID условия |
| `loyalty[levels][][creditConditions][][privilegeSize]` | `float` | Размер скидки, процент или курс начисления бонусов для товаров по обычной цене (в валюте объекта) |
| `loyalty[levels][][creditConditions][][privilegeSizePromo]` | `float` | Размер скидки, процент или курс начисления бонусов для акционных товаров (в валюте объекта) |
| `loyalty[levels][][creditConditions][][items]` | `array of objects (LoyaltyLevelConditionItemOffer)array of objects (LoyaltyLevelConditionItemProduct)array of objects (LoyaltyLevelConditionItemProductGroup)array of objects (LoyaltyLevelConditionItemManufacturer)` | Элементы условия |
| `loyalty[levels][][creditConditions][][items][id]` | `integer` | ID элемента условия |
| `loyalty[levels][][creditConditions][][items][offer]` | `object (Offer)` | Торговое предложение (SKU) |
| `loyalty[levels][][creditConditions][][items][offer][displayName]` | `string` | Название SKU |
| `loyalty[levels][][creditConditions][][items][offer][id]` | `integer` | ID торгового предложения |
| `loyalty[levels][][creditConditions][][items][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `loyalty[levels][][creditConditions][][items][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `loyalty[levels][][creditConditions][][items][offer][name]` | `string` | Название |
| `loyalty[levels][][creditConditions][][items][offer][article]` | `string` | Артикул |
| `loyalty[levels][][creditConditions][][items][offer][vatRate]` | `string` | Ставка НДС |
| `loyalty[levels][][creditConditions][][items][offer][properties][]` | `array` | Свойства SKU |
| `loyalty[levels][][creditConditions][][items][offer][active]` | `boolean` | Активность |
| `loyalty[levels][][creditConditions][][items][offer][unit]` | `object (Unit)` | Единица измерения |
| `loyalty[levels][][creditConditions][][items][offer][unit][code]` | `string` | Символьный код |
| `loyalty[levels][][creditConditions][][items][offer][unit][name]` | `string` | Название |
| `loyalty[levels][][creditConditions][][items][offer][unit][sym]` | `string` | Краткое обозначение |
| `loyalty[levels][][creditConditions][][items][offer][barcode]` | `string` | Штрих-код |
| `loyalty[active]` | `boolean` | Активна |
| `loyalty[blocked]` | `boolean` | Заблокирована |
| `loyalty[currency]` | `string` | Валюта |
| `loyalty[id]` | `integer` | ID программы лояльности |
| `loyalty[name]` | `string` | Название программы лояльности |
| `loyalty[confirmSmsCharge]` | `boolean` | Подтверждать списание по СМС |
| `loyalty[confirmSmsRegistration]` | `boolean` | Подтверждать участие по СМС |
| `loyalty[minResidualCost]` | `float` | Минимальная остаточная стоимость товара/услуги (в валюте объекта) |
| `loyalty[createdAt]` | `DateTime` | Дата создания |
| `loyalty[activatedAt]` | `DateTime` | Дата запуска |
| `loyalty[deactivatedAt]` | `DateTime` | Дата остановки |
| `loyalty[blockedAt]` | `DateTime` | Дата блокировки |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

## Оповещения

### POST /api/v5/notifications/send

Отправка оповещения

Для доступа к методу необходимо разрешение `notification_write`.

Метод отправляет оповещения получателям, которые указаны во взаимоисключающих друг друга полях: `notification[userIds]`, `notification[userGroups]`.

Через `notification[userIds]` можно передать массив идентификаторов существующих в системе пользователей. Все элементы массива должны быть типа `integer`.

На данный момент единственным допустимым значением поля `notification[userGroups]` может быть код группы `superadmins`.

В запросе должно быть заполнено только одно из полей `notification[userIds]` или `notification[userGroups]`.

Поле `notification[type]` может принимать следующие значения: `api.info` - информационное, `api.error` - ошибка.

В поле `notification[message]` допускается использование _HTML-кода_. В целях обеспечения безопасности использования оповещений в тексте сообщения разрешены только некоторые _HTML-теги_: `<b></b>`, `<i></i>`, `<strong></strong>`, `<em></em>`, ` `, `<span>`, `<div>`, `<p>`, `<a href=""></a>`. Длина сообщения не должна превышать 160 символов без учета тегов.

При использовании этого метода нужно учитывать, что по каждому пользователю есть лимит оповещений, которые он может получить за период времени с одного апи-ключа. При отправке сразу нескольким получателям используется лимит пользователя с минимальным остатком оповещений. Отправка оповещений не производится, если в списке получателей присутствует пользователь с нулевым остатком доступных оповещений. В зависимости от типа оповещения имеются два ограничения:

- для оповещения с типом `api.error` установлен лимит в 3 сообщения от модуля для пользователя за последний час;
- для оповещения с типом `api.info` установлен лимит в 10 оповещений от модуля для пользователя за последние 30 минут.

Информация о лимитах возвращается в заголовках ответа:

- `X-RateLimit-Remaining` - количество доступных оповещений;
- `X-RateLimit-Retry-After` - время, когда снова будут доступны оповещения;
- `X-RateLimit-Limit` - лимит оповещений.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `notification` | `object (SerializedApiNotification)` |     |     |
| `notification[type]` | `string` |     | Тип (информационный или ошибка) |
| `notification[message]` | `string` |     | Сообщение (допускается использование html тегов) |
| `notification[userIds][]` | `array` |     | Массив идентификаторов получателей |
| `notification[userGroups][]` | `array` | `{choice of [superadmins]}` | Массив кодов групп получателей |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Оповещение успешно создано и отправлено</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>429</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса. Превышен лимит запросов за последнее время.</p></li></ul></td></tr></tbody></table>

## Заказы

### GET /api/v5/orders

Получение списка заказов, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `order_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтрах `filter[managers][]`, `filter[couriers][]` указывается массив внутренних ID элементов в системе.

В фильтрах `filter[orderTypes][]`, `filter[paymentStatuses][]`, `filter[paymentTypes][]`, `filter[deliveryTypes][]`, `filter[orderMethods][]`, `filter[managerGroups][]` указывается массив символьных кодов элементов.

В фильтрах `filter[sourceName]`, `filter[mediumName]`, `filter[campaignName]`, `filter[keywordName]`, `filter[adContentName]` указывается название элементов.

В фильтре `filter[numbers]` производится точное сравнение с элементами заданного массива строк.

В фильтрах `filter[ids][]` и `filter[externalIds][]` передается массив внутренних и внешних идентификаторов соответственно.

В фильтре `filter[extendedStatus][]` можно указывать один или несколько статусов или групп статусов заказа. Для фильтрации по статусу передается символьный код статуса. Для фильтрации по группе статусов передается символьный код группы статусов плюс постфикс `-group`. Пример: `filter[extendedStatus][]=new&filter[extendedStatus][]=approval-group`.

С помощью фильтра `filter[customFields][]` можно производить поиск по значениям пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» и «Дата-время» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Для пользовательских полей типа `Целое число`, `Число`, `Дата` и `Дата-время` фильтрация осуществляется по диапазону, для остальных типов полей — по точному значению. Имя фильтра соответствует символьному коду поля. Пример: для поля типа `Дата` с символьным кодом `birth_date` доступны фильтры `filter[customFields][birth_date][min]` и `filter[customFields][birth_date][max]`. Для поля типа `Справочник` с символьным кодом `quality` доступен множественный фильтр `filter[customFields][quality][]`.

В фильтре `filter[attachments]` можно указать одно из трех значений:

- `1` - возвращает заказы, у которых есть прикрепленные файлы и вложения к письмам;
- `2` - возвращает заказы, у которых есть прикрепленные файлы;
- `3` - возвращает заказы, у которых нет прикрепленных файлов.

В фильтре `filter[tasksCounts]` можно указать одно из трех значений:

- `1` - возвращает заказы, у которых нет невыполненных задач;
- `2` - возвращает заказы, у которых есть какие-либо невыполненные задачи (как просроченные, так и не просроченные);
- `3` - возвращает только те заказы, у которых среди невыполненных задач есть просроченные.

В фильтре `filter[mgChannels]` указывается массив внутренних ID каналов в системе. Фильтр выбирает заказы созданные через правый виджет чатов.

Пустые поля без значений не возвращаются.

В полях `orderType`, `orderMethod`, `payments[][type]`, `payments[][status]`, `status`, `site`, `delivery[code]` возвращается символьный код элемента.

В полях `managerId`, `sourceId` возвращается внутренний ID сущности в системе.

В поле `customFields` возвращается массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Если адрес доставки указывался в строковом виде, то он будет возвращен в `delivery[address][text]`. Если адрес указывался в детальном виде, то будут возвращены все заполненные поля доставки, а в `delivery[address][text]` будет находиться автоматически сформированное текстовое представление адреса.

Поле `privilegeType` может содержать одно из следующих значений:

- `personal_discount` - персональная скидка клиента;
- `loyalty_level` - расчет скидки или начисление бонусов исходя из настроек уровня ПЛ клиента;
- `loyalty_event` - расчет скидки по событию ПЛ;
- `none` - не применять скидки ПЛ к заказу;

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (OrderFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID заказов |
| `filter[externalIds][]` | `array of strings` |     | Массив externalID заказов |
| `filter[numbers][]` | `array of strings` |     | Массив номеров заказов (не более 100 номеров в одном запросе) |
| `filter[customerId]` | `integer` | `{range: {>=0, <=100000000000}}` | Внутренний ID клиента |
| `filter[customerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID клиента |
| `filter[customer]` | `string` | `{length: {max: 255}}` | Клиент (ФИО или телефон) |
| `filter[customerType]` | `string` | `[customer\|customer_corporate]` | Тип клиента |
| `filter[email]` | `string` | `{length: {max: 255}}` | E-mail |
| `filter[managers][]` | `array of integers` |     | Менеджеры |
| `filter[managerGroups][]` | `array of strings` |     | Группы менеджеров |
| `filter[paymentStatuses][]` | `array of strings` |     | Статусы оплаты |
| `filter[orderTypes][]` | `array of strings` |     | Типы заказа |
| `filter[orderMethods][]` | `array of strings` |     | Способы оформления |
| `filter[product]` | `string` | `{length: {max: 255}}` | Товар (название или артикул) |
| `filter[productSearchType]` | `string` |     |     |
| `filter[extendedStatus][]` | `array of strings` |     | Статус заказа |
| `filter[statusComment]` | `string` | `{length: {max: 255}}` |     |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[vip]` | `boolean` |     | Важный клиент |
| `filter[bad]` | `boolean` |     | Плохой клиент |
| `filter[expired]` | `boolean` |     | Заказ просрочен |
| `filter[call]` | `boolean` |     | Требуется позвонить |
| `filter[online]` | `boolean` |     | Клиент на сайте |
| `filter[paymentTypes][]` | `array of strings` |     | Типы оплаты |
| `filter[deliveryStates][]` | `array of strings` | `{choice of [cancel\|cancel_force\|error\|none\|processing\|success]}` | Статусы оформления |
| `filter[deliveryTypes][]` | `array of strings` |     | Типы доставки |
| `filter[deliveryServices][]` | `array of strings` |     | Службы доставки |
| `filter[countries][]` | `array of strings` |     | Страны |
| `filter[region]` | `string` | `{length: {max: 255}}` | Регион |
| `filter[city]` | `string` | `{length: {max: 255}}` | Город |
| `filter[index]` | `string` |     | Почтовый индекс |
| `filter[metro]` | `string` | `{length: {max: 255}}` | Метро |
| `filter[sourceName]` | `string` | `{length: {max: 255}}` | Источник |
| `filter[mediumName]` | `string` | `{length: {max: 255}}` | Канал |
| `filter[campaignName]` | `string` | `{length: {max: 255}}` | Кампания |
| `filter[keywordName]` | `string` |     | Ключевое слово |
| `filter[adContentName]` | `string` |     | Содержание кампании |
| `filter[managerComment]` | `string` | `{length: {max: 255}}` | Комментарий менеджера |
| `filter[customerComment]` | `string` | `{length: {max: 255}}` | Комментарий клиента |
| `filter[trackNumber]` | `string` | `{length: {max: 255}}` | Номер отправления в службе доставки |
| `filter[deliveryExternalId]` | `string` |     | Идентификатор в службе доставки |
| `filter[couriers][]` | `array of integers` |     | Курьеры |
| `filter[contragentName]` | `string` | `{length: {max: 255}}` | Полное наименование |
| `filter[contragentTypes][]` | `array of strings` | `{choice of [enterpreneur\|individual\|legal-entity]}` | Типы контрагента |
| `filter[contragentInn]` | `string` | `{match: /\d+/}` | ИНН |
| `filter[contragentKpp]` | `string` | `{match: /\d+/}` | КПП |
| `filter[contragentBik]` | `string` | `{match: /\d+/}` | БИК банка |
| `filter[contragentCorrAccount]` | `string` | `{match: /\d+/}` | Корр. счет банка |
| `filter[contragentBankAccount]` | `string` | `{match: /\d+/}` | Расчетный счет |
| `filter[companyName]` | `string` | `{length: {max: 255}}` | Компания (название) |
| `filter[deliveryAddressNotes]` | `string` | `{length: {max: 255}}` | Примечания к адресу доставки |
| `filter[productGroups][]` | `array of integers` |     |     |
| `filter[shipmentStores][]` | `array of strings` |     | Склады отгрузки |
| `filter[shipped]` | `boolean` |     | Отгружен |
| `filter[attachments]` | `integer` | `[1\|2\|3]` | Прикрепленные объекты (вложения) |
| `filter[receiptFiscalDocumentAttribute]` | `string` | `{length: {max: 255}}` | Фискальный признак документа |
| `filter[receiptStatus]` | `string` | `[done\|fail\|wait]` | Статус фискализации |
| `filter[receiptOperation]` | `string` | `[sell\|sell_refund]` | Операция фискализации |
| `filter[receiptOrderStatus]` | `string` | `[done\|fail\|wait]` | Статус полной фискализации |
| `filter[mgChannels][]` | `array of integers` |     | Каналы чатов |
| `filter[tasksCounts]` | `integer` | `[1\|2\|3]` | Задачи |
| `filter[tags][]` | `array of strings` |     |     |
| `filter[attachedTags][]` | `array of strings` |     |     |
| `filter[createdAtFrom]` | `DateTime` | `Y-m-d` | Дата оформления заказа (от) |
| `filter[createdAtTo]` | `DateTime` | `Y-m-d` | Дата оформления заказа (до) |
| `filter[fullPaidAtFrom]` | `DateTime` | `Y-m-d` | Дата полной оплаты (от) |
| `filter[fullPaidAtTo]` | `DateTime` | `Y-m-d` | Дата полной оплаты (до) |
| `filter[deliveryDateFrom]` | `DateTime` | `Y-m-d` | Дата доставки (от) |
| `filter[deliveryDateTo]` | `DateTime` | `Y-m-d` | Дата доставки (до) |
| `filter[statusUpdatedAtFrom]` | `DateTime` | `Y-m-d` | Дата последнего изменения статуса (от) |
| `filter[statusUpdatedAtTo]` | `DateTime` | `Y-m-d` | Дата последнего изменения статуса (до) |
| `filter[shipmentDateFrom]` | `DateTime` | `Y-m-d` | Дата отгрузки (от) |
| `filter[shipmentDateTo]` | `DateTime` | `Y-m-d` | Дата отгрузки (до) |
| `filter[firstWebVisitFrom]` | `DateTime` | `Y-m-d` | Первое посещение (от) |
| `filter[firstWebVisitTo]` | `DateTime` | `Y-m-d` | Первое посещение (до) |
| `filter[lastWebVisitFrom]` | `DateTime` | `Y-m-d` | Последнее посещение (от) |
| `filter[lastWebVisitTo]` | `DateTime` | `Y-m-d` | Последнее посещение (до) |
| `filter[firstOrderFrom]` | `DateTime` | `Y-m-d` | Первый заказ (от) |
| `filter[firstOrderTo]` | `DateTime` | `Y-m-d` | Первый заказ (до) |
| `filter[lastOrderFrom]` | `DateTime` | `Y-m-d` | Последний заказ (от) |
| `filter[lastOrderTo]` | `DateTime` | `Y-m-d` | Последний заказ (до) |
| `filter[paidAtFrom]` | `DateTime` | `Y-m-d` | Дата оплаты (от) |
| `filter[paidAtTo]` | `DateTime` | `Y-m-d` | Дата оплаты (до) |
| `filter[deliveryTimeFrom]` | `DateTime` | `HH:MM:SS` | Время доставки (с) |
| `filter[deliveryTimeTo]` | `DateTime` | `HH:MM:SS` | Время доставки (до) |
| `filter[minPrice]` | `integer` |     | Стоимость заказа (от) |
| `filter[maxPrice]` | `integer` |     | Стоимость заказа (до) |
| `filter[minCostSumm]` | `integer` |     | Сумма расходов (от) |
| `filter[maxCostSumm]` | `integer` |     | Сумма расходов (до) |
| `filter[minPrepaySumm]` | `integer` |     | Оплачено (от) |
| `filter[maxPrepaySumm]` | `integer` |     | Оплачено (до) |
| `filter[minDeliveryCost]` | `integer` |     | Стоимость доставки (от) |
| `filter[maxDeliveryCost]` | `integer` |     | Стоимость доставки (до) |
| `filter[minDeliveryNetCost]` | `integer` |     | Себестоимость доставки (от) |
| `filter[maxDeliveryNetCost]` | `integer` |     | Себестоимость доставки (до) |
| `filter[minMarginSumm]` | `integer` |     | Валовая прибыль заказа (от) |
| `filter[maxMarginSumm]` | `integer` |     | Валовая прибыль заказа (до) |
| `filter[minPurchaseSumm]` | `integer` |     | Закупочная стоимость заказа (от) |
| `filter[maxPurchaseSumm]` | `integer` |     | Закупочная стоимость заказа (до) |
| `filter[customFields]` | `array` |     | Фильтр по пользовательским полям |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `orders[]` | `array of objects (Order)` | Заказ |
| `orders[][slug]` | `custom handler result for (int)` | `deprecated` Символьный код |
| `orders[][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `orders[][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `orders[][summ]` | `double` | Сумма по товарам/услугам (в валюте объекта) |
| `orders[][currency]` | `string` | Валюта |
| `orders[][id]` | `integer` | ID заказа |
| `orders[][number]` | `string` | Номер заказа |
| `orders[][externalId]` | `string` | Внешний ID заказа |
| `orders[][orderType]` | `string` | Тип заказа |
| `orders[][orderMethod]` | `string` | Способ оформления |
| `orders[][privilegeType]` | `string` | Тип привилегии |
| `orders[][countryIso]` | `string` | ISO код страны |
| `orders[][createdAt]` | `DateTime` | Дата оформления заказа |
| `orders[][statusUpdatedAt]` | `DateTime` | Дата последнего изменения статуса |
| `orders[][totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `orders[][prepaySum]` | `double` | Оплаченная сумма (в валюте объекта) |
| `orders[][purchaseSumm]` | `double` | Общая стоимость закупки (в базовой валюте) |
| `orders[][personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `orders[][loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `orders[][loyaltyLevel][id]` | `integer` | ID уровня |
| `orders[][loyaltyLevel][name]` | `string` | Название уровня |
| `orders[][loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `orders[][loyaltyEventDiscount][id]` | `integer` | ID  |
| `orders[][mark]` | `integer` | Оценка заказа |
| `orders[][markDatetime]` | `DateTime` | Дата и время получение оценки от покупателя |
| `orders[][lastName]` | `string` | Фамилия |
| `orders[][firstName]` | `string` | Имя |
| `orders[][patronymic]` | `string` | Отчество |
| `orders[][phone]` | `string` | Телефон |
| `orders[][additionalPhone]` | `string` | Дополнительный телефон |
| `orders[][email]` | `string` | E-mail |
| `orders[][call]` | `boolean` | Требуется позвонить |
| `orders[][expired]` | `boolean` | Просрочен |
| `orders[][customerComment]` | `string` | Комментарий клиента |
| `orders[][managerComment]` | `string` | Комментарий оператора |
| `orders[][managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `orders[][customer]` | `object (Customer)object (CustomerCorporate)` | Клиент |
| `orders[][customer][type]` | `string` | Тип клиента |
| `orders[][customer][id]` | `integer` | ID клиента |
| `orders[][customer][externalId]` | `string` | Внешний ID клиента |
| `orders[][customer][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `orders[][customer][createdAt]` | `DateTime` | Создан |
| `orders[][customer][managerId]` | `integer` | Менеджер клиента |
| `orders[][customer][vip]` | `boolean` | Важный клиент |
| `orders[][customer][bad]` | `boolean` | Плохой клиент |
| `orders[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `orders[][customer][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `orders[][customer][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][customer][contragent][legalName]` | `string` | Полное наименование |
| `orders[][customer][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][customer][contragent][INN]` | `string` | ИНН |
| `orders[][customer][contragent][OKPO]` | `string` | ОКПО |
| `orders[][customer][contragent][KPP]` | `string` | КПП |
| `orders[][customer][contragent][OGRN]` | `string` | ОГРН |
| `orders[][customer][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][customer][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][customer][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][customer][contragent][BIK]` | `string` | БИК |
| `orders[][customer][contragent][bank]` | `string` | Банк |
| `orders[][customer][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][customer][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][customer][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][customer][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `orders[][customer][tags][][color]` | `string` |     |
| `orders[][customer][tags][][name]` | `string` |     |
| `orders[][customer][tags][][colorCode]` | `string` |     |
| `orders[][customer][tags][][attached]` | `boolean` |     |
| `orders[][customer][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `orders[][customer][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `orders[][customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][customer][personalDiscount]` | `double` | Персональная скидка |
| `orders[][customer][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `orders[][customer][discountCardNumber]` | `string` | Номер дисконтной карты |
| `orders[][customer][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][customer][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][customer][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][customer][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][customer][ordersCount]` | `integer` | Количество заказов |
| `orders[][customer][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][customer][address]` | `object (CustomerAddress)` | Адрес клиента |
| `orders[][customer][address][id]` | `integer` | ID адреса |
| `orders[][customer][address][index]` | `string` | Индекс |
| `orders[][customer][address][countryIso]` | `string` | ISO код страны |
| `orders[][customer][address][region]` | `string` | Регион |
| `orders[][customer][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][customer][address][city]` | `string` | Город |
| `orders[][customer][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][customer][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][customer][address][street]` | `string` | Улица |
| `orders[][customer][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][customer][address][streetType]` | `string` | Тип улицы |
| `orders[][customer][address][building]` | `string` | Дом |
| `orders[][customer][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][customer][address][floor]` | `integer` | Этаж |
| `orders[][customer][address][block]` | `integer` | Подъезд |
| `orders[][customer][address][house]` | `string` | Строение |
| `orders[][customer][address][housing]` | `string` | Корпус |
| `orders[][customer][address][metro]` | `string` | Метро |
| `orders[][customer][address][notes]` | `string` | Примечания к адресу |
| `orders[][customer][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][customer][address][externalId]` | `string` | Внешний ID |
| `orders[][customer][address][name]` | `string` | Наменование адреса |
| `orders[][customer][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `orders[][customer][segments][][id]` | `integer` | Внутренний ID сегмента |
| `orders[][customer][segments][][code]` | `string` | Символьный код |
| `orders[][customer][segments][][name]` | `string` | Название сегмента |
| `orders[][customer][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `orders[][customer][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `orders[][customer][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `orders[][customer][segments][][active]` | `boolean` | Активность сегмента |
| `orders[][customer][maturationTime]` | `integer` | Время «созревания», в секундах |
| `orders[][customer][firstName]` | `string` | Имя |
| `orders[][customer][lastName]` | `string` | Фамилия |
| `orders[][customer][patronymic]` | `string` | Отчество |
| `orders[][customer][sex]` | `string` | Пол |
| `orders[][customer][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `orders[][customer][email]` | `string` | E-mail |
| `orders[][customer][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `orders[][customer][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `orders[][customer][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `orders[][customer][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `orders[][customer][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `orders[][customer][customerSubscriptions][][subscription][name]` | `string` | Название |
| `orders[][customer][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `orders[][customer][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `orders[][customer][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `orders[][customer][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `orders[][customer][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `orders[][customer][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `orders[][customer][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `orders[][customer][phones][][number]` | `string` | Номер телефона |
| `orders[][customer][birthday]` | `DateTime` | День рождения |
| `orders[][customer][source]` | `object (SerializedSource)` | Источник клиента |
| `orders[][customer][source][source]` | `string` | Источник |
| `orders[][customer][source][medium]` | `string` | Канал |
| `orders[][customer][source][campaign]` | `string` | Кампания |
| `orders[][customer][source][keyword]` | `string` | Ключевое слово |
| `orders[][customer][source][content]` | `string` | Содержание кампании |
| `orders[][customer][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `orders[][customer][mgCustomers][][id]` | `integer` | ID клиента |
| `orders[][customer][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `orders[][customer][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `orders[][customer][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `orders[][customer][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `orders[][customer][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `orders[][customer][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `orders[][customer][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `orders[][customer][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `orders[][customer][photoUrl]` | `string` | URL фотографии |
| `orders[][contact]` | `object (Customer)` | Контактное лицо |
| `orders[][contact][type]` | `string` | Тип клиента |
| `orders[][contact][id]` | `integer` | ID клиента |
| `orders[][contact][externalId]` | `string` | Внешний ID клиента |
| `orders[][contact][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `orders[][contact][createdAt]` | `DateTime` | Создан |
| `orders[][contact][managerId]` | `integer` | Менеджер клиента |
| `orders[][contact][vip]` | `boolean` | Важный клиент |
| `orders[][contact][bad]` | `boolean` | Плохой клиент |
| `orders[][contact][site]` | `string` | Магазин, с которого пришел клиент |
| `orders[][contact][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `orders[][contact][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][contact][contragent][legalName]` | `string` | Полное наименование |
| `orders[][contact][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][contact][contragent][INN]` | `string` | ИНН |
| `orders[][contact][contragent][OKPO]` | `string` | ОКПО |
| `orders[][contact][contragent][KPP]` | `string` | КПП |
| `orders[][contact][contragent][OGRN]` | `string` | ОГРН |
| `orders[][contact][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][contact][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][contact][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][contact][contragent][BIK]` | `string` | БИК |
| `orders[][contact][contragent][bank]` | `string` | Банк |
| `orders[][contact][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][contact][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][contact][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][contact][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `orders[][contact][tags][][color]` | `string` |     |
| `orders[][contact][tags][][name]` | `string` |     |
| `orders[][contact][tags][][colorCode]` | `string` |     |
| `orders[][contact][tags][][attached]` | `boolean` |     |
| `orders[][contact][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `orders[][contact][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `orders[][contact][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][contact][personalDiscount]` | `double` | Персональная скидка |
| `orders[][contact][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `orders[][contact][discountCardNumber]` | `string` | Номер дисконтной карты |
| `orders[][contact][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][contact][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][contact][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][contact][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][contact][ordersCount]` | `integer` | Количество заказов |
| `orders[][contact][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][contact][address]` | `object (CustomerAddress)` | Адрес клиента |
| `orders[][contact][address][id]` | `integer` | ID адреса |
| `orders[][contact][address][index]` | `string` | Индекс |
| `orders[][contact][address][countryIso]` | `string` | ISO код страны |
| `orders[][contact][address][region]` | `string` | Регион |
| `orders[][contact][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][contact][address][city]` | `string` | Город |
| `orders[][contact][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][contact][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][contact][address][street]` | `string` | Улица |
| `orders[][contact][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][contact][address][streetType]` | `string` | Тип улицы |
| `orders[][contact][address][building]` | `string` | Дом |
| `orders[][contact][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][contact][address][floor]` | `integer` | Этаж |
| `orders[][contact][address][block]` | `integer` | Подъезд |
| `orders[][contact][address][house]` | `string` | Строение |
| `orders[][contact][address][housing]` | `string` | Корпус |
| `orders[][contact][address][metro]` | `string` | Метро |
| `orders[][contact][address][notes]` | `string` | Примечания к адресу |
| `orders[][contact][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][contact][address][externalId]` | `string` | Внешний ID |
| `orders[][contact][address][name]` | `string` | Наменование адреса |
| `orders[][contact][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `orders[][contact][segments][][id]` | `integer` | Внутренний ID сегмента |
| `orders[][contact][segments][][code]` | `string` | Символьный код |
| `orders[][contact][segments][][name]` | `string` | Название сегмента |
| `orders[][contact][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `orders[][contact][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `orders[][contact][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `orders[][contact][segments][][active]` | `boolean` | Активность сегмента |
| `orders[][contact][maturationTime]` | `integer` | Время «созревания», в секундах |
| `orders[][contact][firstName]` | `string` | Имя |
| `orders[][contact][lastName]` | `string` | Фамилия |
| `orders[][contact][patronymic]` | `string` | Отчество |
| `orders[][contact][sex]` | `string` | Пол |
| `orders[][contact][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `orders[][contact][email]` | `string` | E-mail |
| `orders[][contact][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `orders[][contact][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `orders[][contact][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `orders[][contact][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `orders[][contact][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `orders[][contact][customerSubscriptions][][subscription][name]` | `string` | Название |
| `orders[][contact][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `orders[][contact][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `orders[][contact][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `orders[][contact][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `orders[][contact][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `orders[][contact][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `orders[][contact][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `orders[][contact][phones][][number]` | `string` | Номер телефона |
| `orders[][contact][birthday]` | `DateTime` | День рождения |
| `orders[][contact][source]` | `object (SerializedSource)` | Источник клиента |
| `orders[][contact][source][source]` | `string` | Источник |
| `orders[][contact][source][medium]` | `string` | Канал |
| `orders[][contact][source][campaign]` | `string` | Кампания |
| `orders[][contact][source][keyword]` | `string` | Ключевое слово |
| `orders[][contact][source][content]` | `string` | Содержание кампании |
| `orders[][contact][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `orders[][contact][mgCustomers][][id]` | `integer` | ID клиента |
| `orders[][contact][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `orders[][contact][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `orders[][contact][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `orders[][contact][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `orders[][contact][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `orders[][contact][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `orders[][contact][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `orders[][contact][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `orders[][contact][photoUrl]` | `string` | URL фотографии |
| `orders[][company]` | `object (Company)` | Компания |
| `orders[][company][id]` | `integer` | ID компании |
| `orders[][company][externalId]` | `string` | Внешний ID компании |
| `orders[][company][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `orders[][company][customer][site]` | `string` | Символьный код магазина |
| `orders[][company][customer][id]` | `integer` | Внутренний ID клиента |
| `orders[][company][customer][externalId]` | `string` | Внешний ID клиента |
| `orders[][company][customer][type]` | `string` | Тип клиента |
| `orders[][company][active]` | `boolean` | Активность |
| `orders[][company][name]` | `string` | Наименование |
| `orders[][company][brand]` | `string` | Бренд |
| `orders[][company][site]` | `string` | Сайт компании |
| `orders[][company][createdAt]` | `DateTime` | Дата создания |
| `orders[][company][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `orders[][company][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][company][contragent][legalName]` | `string` | Полное наименование |
| `orders[][company][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][company][contragent][INN]` | `string` | ИНН |
| `orders[][company][contragent][OKPO]` | `string` | ОКПО |
| `orders[][company][contragent][KPP]` | `string` | КПП |
| `orders[][company][contragent][OGRN]` | `string` | ОГРН |
| `orders[][company][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][company][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][company][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][company][contragent][BIK]` | `string` | БИК |
| `orders[][company][contragent][bank]` | `string` | Банк |
| `orders[][company][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][company][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][company][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][company][address]` | `object (CustomerAddress)` | Адрес |
| `orders[][company][address][id]` | `integer` | ID адреса |
| `orders[][company][address][index]` | `string` | Индекс |
| `orders[][company][address][countryIso]` | `string` | ISO код страны |
| `orders[][company][address][region]` | `string` | Регион |
| `orders[][company][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][company][address][city]` | `string` | Город |
| `orders[][company][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][company][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][company][address][street]` | `string` | Улица |
| `orders[][company][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][company][address][streetType]` | `string` | Тип улицы |
| `orders[][company][address][building]` | `string` | Дом |
| `orders[][company][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][company][address][floor]` | `integer` | Этаж |
| `orders[][company][address][block]` | `integer` | Подъезд |
| `orders[][company][address][house]` | `string` | Строение |
| `orders[][company][address][housing]` | `string` | Корпус |
| `orders[][company][address][metro]` | `string` | Метро |
| `orders[][company][address][notes]` | `string` | Примечания к адресу |
| `orders[][company][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][company][address][externalId]` | `string` | Внешний ID |
| `orders[][company][address][name]` | `string` | Наменование адреса |
| `orders[][company][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][company][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][company][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][company][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][company][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][company][ordersCount]` | `integer` | Количество заказов |
| `orders[][company][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][contragent]` | `object (OrderContragent)` | Реквизиты |
| `orders[][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][contragent][legalName]` | `string` | Полное наименование |
| `orders[][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][contragent][INN]` | `string` | ИНН |
| `orders[][contragent][OKPO]` | `string` | ОКПО |
| `orders[][contragent][KPP]` | `string` | КПП |
| `orders[][contragent][OGRN]` | `string` | ОГРН |
| `orders[][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][contragent][BIK]` | `string` | БИК |
| `orders[][contragent][bank]` | `string` | Банк |
| `orders[][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `orders[][delivery][code]` | `string` | Код типа доставки |
| `orders[][delivery][integrationCode]` | `string` | Интеграционный код типа доставки |
| `orders[][delivery][data]` | `object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData)` | Данные службы доставки, подключенной через API |
| `orders[][delivery][data][externalId]` | `string` | Идентификатор в службе доставки |
| `orders[][delivery][data][trackNumber]` | `string` | Номер отправления (поле `deprecated` на запись) |
| `orders[][delivery][data][status]` | `string` | Код статуса доставки |
| `orders[][delivery][data][locked]` | `boolean` | Не синхронизировать со службой доставки |
| `orders[][delivery][data][pickuppointAddress]` | `string` | Адрес пункта самовывоза |
| `orders[][delivery][data][days]` | `string` | Ориентировочный срок доставки |
| `orders[][delivery][data][statusText]` | `string` | Наименование статуса доставки |
| `orders[][delivery][data][statusDate]` | `DateTime` | Дата статуса доставки |
| `orders[][delivery][data][tariff]` | `string` | Код тарифа |
| `orders[][delivery][data][tariffName]` | `string` | Наименование тарифа |
| `orders[][delivery][data][pickuppointId]` | `string` | Идентификатор пункта самовывоза |
| `orders[][delivery][data][pickuppointSchedule]` | `string` | Режим работы пункта самовывоза |
| `orders[][delivery][data][pickuppointPhone]` | `string` | Телефон пункта самовывоза |
| `orders[][delivery][data][payerType]` | `string` | Плательщик за доставку |
| `orders[][delivery][data][statusComment]` | `string` | Комментарий к статусу доставки |
| `orders[][delivery][data][cost]` | `float` | Стоимость доставки, полученная из службы доставки (в валюте объекта) |
| `orders[][delivery][data][minTerm]` | `integer` | Минимальный срок доставки |
| `orders[][delivery][data][maxTerm]` | `integer` | Максимальный срок доставки |
| `orders[][delivery][data][shipmentpointId]` | `string` | Идентификатор терминала отгрузки |
| `orders[][delivery][data][shipmentpointName]` | `string` | Наименование терминала отгрузки |
| `orders[][delivery][data][shipmentpointAddress]` | `string` | Адрес терминала отгрузки |
| `orders[][delivery][data][shipmentpointSchedule]` | `string` | Режим работы терминала отгрузки |
| `orders[][delivery][data][shipmentpointPhone]` | `string` | Телефон терминала отгрузки |
| `orders[][delivery][data][shipmentpointCoordinateLatitude]` | `string` | Координаты терминала отгрузки, широта |
| `orders[][delivery][data][shipmentpointCoordinateLongitude]` | `string` | Координаты терминала отгрузки, долгота |
| `orders[][delivery][data][pickuppointName]` | `string` | Наименование пункта самовывоза |
| `orders[][delivery][data][pickuppointCoordinateLatitude]` | `string` | Координаты ПВЗ, широта |
| `orders[][delivery][data][pickuppointCoordinateLongitude]` | `string` | Координаты ПВЗ, долгота |
| `orders[][delivery][data][extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `orders[][delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` | Код |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` | Значение |
| `orders[][delivery][data][itemDeclaredValues][][value]` | `double` | Объявленная стоимость товара |
| `orders[][delivery][data][packages][]` | `array of objects (Package)` | Упаковки |
| `orders[][delivery][data][packages][][packageId]` | `string` | Идентификатор упаковки |
| `orders[][delivery][data][packages][][weight]` | `double` | Вес г. |
| `orders[][delivery][data][packages][][length]` | `integer` | Длина мм. |
| `orders[][delivery][data][packages][][width]` | `integer` | Ширина мм. |
| `orders[][delivery][data][packages][][height]` | `integer` | Высота мм. |
| `orders[][delivery][data][packages][][items][]` | `array of objects (PackageItem)` | Содержимое упаковки |
| `orders[][delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` | Код |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` | Значение |
| `orders[][delivery][data][packages][][items][][quantity]` | `double` | Количество товара в упаковке |
| `orders[][delivery][service]` | `object (SerializedDeliveryService)` |     |
| `orders[][delivery][service][name]` | `string` | Название |
| `orders[][delivery][service][code]` | `string` | Символьный код |
| `orders[][delivery][service][active]` | `boolean` | Статус активности |
| `orders[][delivery][cost]` | `double` | Стоимость доставки |
| `orders[][delivery][netCost]` | `double` | Себестоимость доставки |
| `orders[][delivery][date]` | `DateTime` | Дата доставки |
| `orders[][delivery][time]` | `object (TimeInterval)` | Информация о временном диапазоне |
| `orders[][delivery][time][from]` | `DateTime` | Время "с" |
| `orders[][delivery][time][to]` | `DateTime` | Время "до" |
| `orders[][delivery][time][custom]` | `string` | Временной диапазон в свободной форме |
| `orders[][delivery][address]` | `object (OrderDeliveryAddress)` | Адрес доставки |
| `orders[][delivery][address][index]` | `string` | Индекс |
| `orders[][delivery][address][countryIso]` | `string` | ISO код страны |
| `orders[][delivery][address][region]` | `string` | Регион |
| `orders[][delivery][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][delivery][address][city]` | `string` | Город |
| `orders[][delivery][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][delivery][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][delivery][address][street]` | `string` | Улица |
| `orders[][delivery][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][delivery][address][streetType]` | `string` | Тип улицы |
| `orders[][delivery][address][building]` | `string` | Дом |
| `orders[][delivery][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][delivery][address][floor]` | `integer` | Этаж |
| `orders[][delivery][address][block]` | `integer` | Подъезд |
| `orders[][delivery][address][house]` | `string` | Строение |
| `orders[][delivery][address][housing]` | `string` | Корпус |
| `orders[][delivery][address][metro]` | `string` | Метро |
| `orders[][delivery][address][notes]` | `string` | Примечания к адресу |
| `orders[][delivery][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][delivery][vatRate]` | `string` | Ставка НДС |
| `orders[][site]` | `string` | Магазин |
| `orders[][status]` | `string` | Статус заказа |
| `orders[][statusComment]` | `string` | Комментарий к последнему изменению статуса |
| `orders[][source]` | `object (SerializedSource)` | Источник заказа |
| `orders[][source][source]` | `string` | Источник |
| `orders[][source][medium]` | `string` | Канал |
| `orders[][source][campaign]` | `string` | Кампания |
| `orders[][source][keyword]` | `string` | Ключевое слово |
| `orders[][source][content]` | `string` | Содержание кампании |
| `orders[][items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `orders[][items][][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `orders[][items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `orders[][items][][markingCodes][]` | `array of strings` | Коды маркировки |
| `orders[][items][][id]` | `integer` | ID позиции в заказе |
| `orders[][items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][items][][externalIds][][code]` | `string` | Код |
| `orders[][items][][externalIds][][value]` | `string` | Значение |
| `orders[][items][][priceType]` | `object (PriceType)` | Тип цены |
| `orders[][items][][priceType][code]` | `string` | Код типа цены |
| `orders[][items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `orders[][items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `orders[][items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `orders[][items][][discounts][][amount]` | `float` | Сумма скидки |
| `orders[][items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `orders[][items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `orders[][items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `orders[][items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `orders[][items][][vatRate]` | `string` | Ставка НДС |
| `orders[][items][][createdAt]` | `DateTime` | Дата создания позиции в системе |
| `orders[][items][][quantity]` | `float` | Количество |
| `orders[][items][][status]` | `string` | Статус позиции в заказе |
| `orders[][items][][comment]` | `string` | Комментарий к позиции в заказе |
| `orders[][items][][offer]` | `object (Offer)` | Торговое предложение |
| `orders[][items][][offer][displayName]` | `string` | Название SKU |
| `orders[][items][][offer][id]` | `integer` | ID торгового предложения |
| `orders[][items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `orders[][items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `orders[][items][][offer][name]` | `string` | Название |
| `orders[][items][][offer][article]` | `string` | Артикул |
| `orders[][items][][offer][vatRate]` | `string` | Ставка НДС |
| `orders[][items][][offer][properties][]` | `array` | Свойства SKU |
| `orders[][items][][offer][unit]` | `object (Unit)` | Единица измерения |
| `orders[][items][][offer][unit][code]` | `string` | Символьный код |
| `orders[][items][][offer][unit][name]` | `string` | Название |
| `orders[][items][][offer][unit][sym]` | `string` | Краткое обозначение |
| `orders[][items][][offer][barcode]` | `string` | Штрих-код |
| `orders[][items][][isCanceled]` | `boolean` | Данная позиция в заказе является отменной |
| `orders[][items][][properties][]` | `array` | \[массив\] Дополнительные свойства позиции в заказе |
| `orders[][items][][properties][][code]` | `string` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `orders[][items][][properties][][name]` | `string` | Имя свойства |
| `orders[][items][][properties][][value]` | `string` | Значение свойства |
| `orders[][items][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `orders[][items][][ordering]` | `integer` | Порядок |
| `orders[][fullPaidAt]` | `DateTime` | Дата полной оплаты |
| `orders[][payments][]` | `array of objects (Payment)` | Платежи |
| `orders[][payments][][id]` | `integer` | Внутренний ID |
| `orders[][payments][][status]` | `string` | Статус оплаты |
| `orders[][payments][][type]` | `string` | Тип оплаты |
| `orders[][payments][][externalId]` | `string` | Внешний ID платежа |
| `orders[][payments][][amount]` | `double` | Сумма платежа (в валюте объекта) |
| `orders[][payments][][paidAt]` | `DateTime` | Дата оплаты |
| `orders[][payments][][comment]` | `string` | Комментарий |
| `orders[][fromApi]` | `boolean` | Заказ поступил через API |
| `orders[][weight]` | `double` | Вес |
| `orders[][length]` | `integer` | Длина |
| `orders[][width]` | `integer` | Ширина |
| `orders[][height]` | `integer` | Высота |
| `orders[][shipmentStore]` | `string` | Склад отгрузки |
| `orders[][shipmentDate]` | `DateTime` | Дата отгрузки |
| `orders[][shipped]` | `boolean` | Заказ отгружен |
| `orders[][links][]` | `array of objects (OrderLink)` | Связь заказов |
| `orders[][links][][order]` | `object (LinkedOrder)` | Связанный заказ |
| `orders[][links][][order][id]` | `integer` | ID связанного заказа |
| `orders[][links][][order][number]` | `string` | Номер связанного заказа |
| `orders[][links][][order][externalId]` | `string` | Внешний ID связанного заказа |
| `orders[][links][][createdAt]` | `DateTime` | Дата/время создания связи с заказом |
| `orders[][links][][comment]` | `string` | Комментарий |
| `orders[][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][clientId]` | `string` | Метка клиента Google Analytics |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/combine

Объединение заказов

Для доступа к методу необходимо разрешение `order_write`.

Позволяет объединить заказы.

Товары заказа в параметре `resultOrder` будут объединены с товарами заказа `order`, после чего заказ `order` будет безвозвратно удален.

Операция выполняется в асинхронном режиме. Успешный ответ `success=true` означает, что операция принята к выполнению, но еще не завершена. Фактический результат операции можно отслеживать при помощи метода `/api/v5/orders/history` по тем заказам, которые будут удалены в процессе объединения (параметр ответа `history[][combinedTo]`).

В параметре `technique` можно указать стратегию объединения в случае одинаковых товаров в составах заказов

- `ours` использовать количество товара из текущего заказа
- `summ` суммировать количество товара обоих заказов
- `theirs` использовать количество товара из объединяемого заказа

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `order` | `object (SerializedOrderReference)` |     | Заказ будет удален в результате объединения |
| `order[id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID заказа |
| `resultOrder` | `object (SerializedOrderReference)` |     | Заказ, в который произойдет объединение |
| `resultOrder[id]` | `integer` | `{not blank}{range: {>=1, <=4294967295}}}` | Внутренний ID заказа |
| `technique` | `string` |     | Способ объединения в случае одинаковых товаров в составах заказов |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errors` | `array` | Ошибки при объединении заказов |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/create

Создание заказа

Для доступа к методу необходимо разрешение `order_write`.

Метод создает заказ и возвращает внутренний ID созданного заказа.

Если не указывать `order[createdAt]`, то будет использовано текущее время в качестве даты/времени оформления заказа.

Если требуется привязать заказ к существующему клиенту, то необходимо передать внешний ID клиента в поле `order[customer][externalId]`, внутренний ID клиента в поле `order[customer][id]` либо идентификатор клиента в Daemon Collector в поле `order[customer][browserId]`. Поиск клиента будет осуществляться в рамках магазинов, к которым есть доступ у используемого API-ключа. Если не указывать `order[customer]`, то клиент будет автоматически создан на основе данных из заказа.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

В полях `order[orderType]`, `order[orderMethod]`, `order[payments][][type]`, `order[payments][][status]`, `order[status]`, `order[shipmentStore]`, `order[delivery][code]`, `order[items][][status]` указывается символьный код элемента.

В полях `order[managerId]`, `order[sourceId]` указывается внутренний ID сущности в системе.

Нельзя изменять комментарий `order[statusComment]` без изменения статуса заказа `order[status]`.

Товары заказа указываются в поле `order[items][]`. Не переданные в запросе на редактирование товары удаляются из заказа. Если товар присутствует в каталоге, то необходимо установить значение одного из следующих полей:

- `order[items][][offer][id]` – внутренний ID торгового предложения;
- `order[items][][offer][externalId]` – внешний ID товара или торгового предложения (SKU);
- `order[items][][offer][xmlId]` – ID торгового предложения в складской системе.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке.

В случае, если ни один из идентификаторов товара не передан либо товар не найден, то товар будет автоматически создан на основе данных полей `order[items][][initialPrice]`, `order[items][][purchasePrice]`, `order[items][][productName]`, при этом данная позиция товара в заказе не привязывается к товару в каталоге.

Адрес доставки `order[delivery][address]` можно указывать либо в строковом виде в поле `order[delivery][address][text]`, либо в подробном виде, заполняя все поля кроме `order[delivery][address][text]`.

В поле `order[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Для работы с типами цен необходимо, чтобы в справочнике было активно более одного типа цен. Для передачи типа цены для товарной позиции в заказе необходимо передать код нужного типа цен в поле `order[items][][priceType][code]`. Рекомендуется вместе с типом цены передавать актуальное значение цены товара через `order[items][][initialPrice]`. Если передать тип цены `order[items][][priceType][code]` без значения цены `order[items][][initialPrice]`, то в качестве цены товарной позиции возьмется текущее значения данного типа цен для данного товара. Для новой товарной позиции рекомендуется всегда передавать цену `order[items][][initialPrice]` явно, на случай если актуальная цена еще не была загружена в систему. Если для товара не передать тип цены `order[items][][priceType][code]`, то в карточке заказа для товарной позиции в типе цены будет указанно `Без типа`. В случае, если в системе используется только базовый тип цен, то параметр `order[items][][priceType][code]` следует опустить.

Порядок позиций заказа `order[items][]` сохраняется в ответе.

Параметры `order[items][][externalId]` и `order[items][][externalIds]` являются не обязательными.

Одновременно можно указывать или значение внешнего идентификатора `order[items][][externalId]` или массив внешних идентификаторов `order[items][][externalIds]`.

Значение внешнего идентификатора `order[items][][externalId]` будет записано в массив `order[items][][externalIds]` с кодом `default`.

Значения внешних идентификаторов `order[items][][externalIds][][value]` должны быть уникальны по коду `order[items][][externalIds][][code]` в пределах одного заказа.

Поле `privilegeType` может содержать одно из следующих значений:

- `personal_discount` - персональная скидка клиента;
- `loyalty_level` - расчет скидки или начисление бонусов исходя из настроек уровня ПЛ клиента;
- `loyalty_event` - расчет скидки по событию ПЛ;
- `none` - не применять скидки ПЛ к заказу;

Для применения Программы лояльности к заказу необходимо чтобы соблюдались условия:

- магазин заказа совпадал с магазином нужной ПЛ;
- у клиента в заказе было создано Участие в ПЛ;
- указано поле `privilegeType`;

Если в `privilegeType` указано значение `loyalty_event` и событие дает скидку, то необходимо указать ID в поле `loyaltyEventDiscountId`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `order` | `object (SerializedOrder)` |     |     |
| `order[number]` | `string` |     | Номер заказа |
| `order[externalId]` | `string` |     | Внешний ID заказа |
| `order[privilegeType]` | `string` |     | Тип привилегии |
| `order[countryIso]` | `string` |     | ISO код страны |
| `order[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата оформления заказа |
| `order[statusUpdatedAt]` | `DateTime` | `Y-m-d H:i:s` | Дата последнего изменения статуса |
| `order[discountManualAmount]` | `double` |     | Денежная скидка на весь заказ (в валюте объекта) |
| `order[discountManualPercent]` | `double` |     | Процентная скидка на весь заказ |
| `order[mark]` | `integer` |     | Оценка заказа |
| `order[markDatetime]` | `DateTime` | `Y-m-d H:i:s` | Дата и время получение оценки от покупателя |
| `order[lastName]` | `string` |     | Фамилия |
| `order[firstName]` | `string` |     | Имя |
| `order[patronymic]` | `string` |     | Отчество |
| `order[phone]` | `string` |     | Телефон |
| `order[additionalPhone]` | `string` |     | Дополнительный телефон |
| `order[email]` | `string` |     | E-mail |
| `order[call]` | `boolean` |     | Требуется позвонить |
| `order[expired]` | `boolean` |     | Просрочен |
| `order[customerComment]` | `string` |     | Комментарий клиента |
| `order[managerComment]` | `string` |     | Комментарий оператора |
| `order[contragent]` | `object (OrderContragent)` |     | Реквизиты |
| `order[contragent][contragentType]` | `string` |     | Тип контрагента |
| `order[contragent][legalName]` | `string` |     | Полное наименование |
| `order[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `order[contragent][INN]` | `string` |     | ИНН |
| `order[contragent][OKPO]` | `string` |     | ОКПО |
| `order[contragent][KPP]` | `string` |     | КПП |
| `order[contragent][OGRN]` | `string` |     | ОГРН |
| `order[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `order[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `order[contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `order[contragent][BIK]` | `string` |     | БИК |
| `order[contragent][bank]` | `string` |     | Банк |
| `order[contragent][bankAddress]` | `string` |     | Адрес банка |
| `order[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `order[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `order[statusComment]` | `string` |     | Комментарий к последнему изменению статуса |
| `order[weight]` | `double` |     | Вес |
| `order[length]` | `integer` |     | Длина |
| `order[width]` | `integer` |     | Ширина |
| `order[height]` | `integer` |     | Высота |
| `order[shipmentDate]` | `DateTime` | `Y-m-d` | Дата отгрузки |
| `order[shipped]` | `boolean` |     | Заказ отгружен |
| `order[dialogId]` | `object (MGDialog)` |     | Идентификатор диалога Чатов |
| `order[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `order[orderType]` | `string` |     | Тип заказа |
| `order[orderMethod]` | `string` |     | Способ оформления |
| `order[customer]` | `object (SerializedRelationCustomer)` |     | Клиент |
| `order[customer][id]` | `integer` |     | Внутренний ID клиента |
| `order[customer][externalId]` | `string` |     | Внешний ID клиента |
| `order[customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `order[customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `order[customer][type]` | `string` |     | Тип клиента (передаётся когда нужно создать нового клиента) |
| `order[customer][nickName]` | `string` |     | Наименование корпоративного клиента (передаётся когда нужно создать нового корпоративного клиента) |
| `order[contact]` | `object (SerializedRelationAbstractCustomer)` |     | Контактное лицо |
| `order[contact][id]` | `integer` |     | Внутренний ID клиента |
| `order[contact][externalId]` | `string` |     | Внешний ID клиента |
| `order[contact][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `order[contact][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `order[company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `order[company][id]` | `integer` |     | ID  |
| `order[company][externalId]` | `string` |     | Внешний ID |
| `order[managerId]` | `integer` |     | Менеджер, прикрепленный к заказу |
| `order[status]` | `string` |     | Статус заказа |
| `order[items][]` | `array of objects (SerializedOrderProduct)` |     |     |
| `order[items][][markingCodes][]` | `array of strings` |     | Коды маркировки |
| `order[items][][initialPrice]` | `double` |     | Цена товара/SKU (в валюте объекта) |
| `order[items][][discountManualAmount]` | `double` |     | Денежная скидка на единицу товара (в валюте объекта) |
| `order[items][][discountManualPercent]` | `double` |     | Процентная скидка на единицу товара |
| `order[items][][vatRate]` | `string` |     | Ставка НДС |
| `order[items][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания позиции в системе |
| `order[items][][quantity]` | `float` |     | Количество |
| `order[items][][comment]` | `string` |     | Комментарий к позиции в заказе |
| `order[items][][properties][]` | `array` |     | \[массив\] Дополнительные свойства позиции в заказе |
| `order[items][][properties][][code]` | `string` | `{not blank}{match: /^[a-zA-Z0-9_][a-zA-Z0-9_\-:]*$/D}}` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `order[items][][properties][][name]` | `string` | `{not blank}` | Имя свойства |
| `order[items][][properties][][value]` | `string` | `{not blank}` | Значение свойства |
| `order[items][][purchasePrice]` | `double` |     | Закупочная цена (в базовой валюте) |
| `order[items][][ordering]` | `integer` |     | Порядок |
| `order[items][][offer]` | `object (SerializedOrderProductOffer)` |     | Торговое предложение |
| `order[items][][offer][id]` | `integer` |     | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `order[items][][offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `order[items][][productName]` | `string` |     | Название товара |
| `order[items][][status]` | `string` |     | Статус позиции в заказе |
| `order[items][][priceType]` | `object (PriceType)` |     | Тип цены |
| `order[items][][priceType][code]` | `string` |     | Код типа цены |
| `order[items][][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` |     | Код |
| `order[items][][externalIds][][value]` | `string` |     | Значение |
| `order[delivery]` | `object (SerializedOrderDelivery)` |     | Данные о доставке |
| `order[delivery][code]` | `string` |     | Код типа доставки |
| `order[delivery][data]` | object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData) |     | Данные службы доставки, подключенной через API |
| `order[delivery][data][externalId]` | `string` |     | Идентификатор в службе доставки |
| `order[delivery][data][trackNumber]` | `string` |     | Номер отправления (поле `deprecated` на запись) |
| `order[delivery][data][locked]` | `boolean` |     | Не синхронизировать со службой доставки |
| `order[delivery][data][tariff]` | `string` |     | Код тарифа |
| `order[delivery][data][pickuppointId]` | `string` |     | Идентификатор пункта самовывоза |
| `order[delivery][data][payerType]` | `string` |     | Плательщик за доставку |
| `order[delivery][data][shipmentpointId]` | `string` |     | Идентификатор терминала отгрузки |
| `order[delivery][data][extraData]` | `array` |     | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `order[delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |     |
| `order[delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `order[delivery][data][itemDeclaredValues][][value]` | `double` |     | Объявленная стоимость товара |
| `order[delivery][data][packages][]` | `array of objects (Package)` |     | Упаковки |
| `order[delivery][data][packages][][packageId]` | `string` |     | Идентификатор упаковки |
| `order[delivery][data][packages][][weight]` | `double` |     | Вес г. |
| `order[delivery][data][packages][][length]` | `integer` |     | Длина мм. |
| `order[delivery][data][packages][][width]` | `integer` |     | Ширина мм. |
| `order[delivery][data][packages][][height]` | `integer` |     | Высота мм. |
| `order[delivery][data][packages][][items][]` | `array of objects (PackageItem)` |     | Содержимое упаковки |
| `order[delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `order[delivery][data][packages][][items][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `order[delivery][data][packages][][items][][quantity]` | `double` |     | Количество товара в упаковке |
| `order[delivery][service]` | `object (SerializedDeliveryService)` |     |     |
| `order[delivery][service][name]` | `string` |     | Название |
| `order[delivery][service][code]` | `string` |     | Символьный код |
| `order[delivery][service][active]` | `boolean` |     | Статус активности |
| `order[delivery][service][deliveryType]` | `string` |     | Тип доставки |
| `order[delivery][cost]` | `double` |     | Стоимость доставки |
| `order[delivery][netCost]` | `double` |     | Себестоимость доставки |
| `order[delivery][date]` | `DateTime` | `Y-m-d` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` |     | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | `H:i` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | `H:i` | Время "до" |
| `order[delivery][time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` |     | Адрес доставки |
| `order[delivery][address][index]` | `string` |     | Индекс |
| `order[delivery][address][countryIso]` | `string` |     | ISO код страны |
| `order[delivery][address][region]` | `string` |     | Регион |
| `order[delivery][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` |     | Город |
| `order[delivery][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` |     | Тип населенного пункта |
| `order[delivery][address][street]` | `string` |     | Улица |
| `order[delivery][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` |     | Тип улицы |
| `order[delivery][address][building]` | `string` |     | Дом |
| `order[delivery][address][flat]` | `string` |     | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` |     | Этаж |
| `order[delivery][address][block]` | `integer` |     | Подъезд |
| `order[delivery][address][house]` | `string` |     | Строение |
| `order[delivery][address][housing]` | `string` |     | Корпус |
| `order[delivery][address][metro]` | `string` |     | Метро |
| `order[delivery][address][notes]` | `string` |     | Примечания к адресу |
| `order[delivery][address][text]` | `string` |     | Адрес в текстовом виде |
| `order[delivery][vatRate]` | `string` |     | Ставка НДС |
| `order[source]` | `object (SerializedSource)` |     | Источник заказа |
| `order[source][source]` | `string` |     | Источник |
| `order[source][medium]` | `string` |     | Канал |
| `order[source][campaign]` | `string` |     | Кампания |
| `order[source][keyword]` | `string` |     | Ключевое слово |
| `order[source][content]` | `string` |     | Содержание кампании |
| `order[shipmentStore]` | `string` |     | Склад отгрузки |
| `order[payments][]` | `array of objects (SerializedPayment)` |     | Платежи |
| `order[payments][][externalId]` | `string` |     | Внешний ID платежа |
| `order[payments][][amount]` | `double` |     | Сумма платежа (в валюте объекта) |
| `order[payments][][paidAt]` | `DateTime` | `Y-m-d H:i:s` | Дата оплаты |
| `order[payments][][comment]` | `string` |     | Комментарий |
| `order[payments][][type]` | `string` |     | Тип оплаты |
| `order[payments][][status]` | `string` |     | Статус оплаты |
| `order[loyaltyEventDiscountId]` | `integer` |     | ID скидки по событию программы лояльности |
| `order[applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |
| `order[isFromCart]` | `boolean` |     | Заказ создан из корзины |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного заказа |
| `order` | `object (CreateOrder)` |     |
| `order[slug]` | `custom handler result for (int)` | `deprecated` Символьный код |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[summ]` | `double` | Сумма по товарам/услугам (в валюте объекта) |
| `order[currency]` | `string` | Валюта |
| `order[id]` | `integer` | ID заказа |
| `order[number]` | `string` | Номер заказа |
| `order[externalId]` | `string` | Внешний ID заказа |
| `order[orderType]` | `string` | Тип заказа |
| `order[orderMethod]` | `string` | Способ оформления |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[countryIso]` | `string` | ISO код страны |
| `order[createdAt]` | `DateTime` | Дата оформления заказа |
| `order[statusUpdatedAt]` | `DateTime` | Дата последнего изменения статуса |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[prepaySum]` | `double` | Оплаченная сумма (в валюте объекта) |
| `order[purchaseSumm]` | `double` | Общая стоимость закупки (в базовой валюте) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[mark]` | `integer` | Оценка заказа |
| `order[markDatetime]` | `DateTime` | Дата и время получение оценки от покупателя |
| `order[lastName]` | `string` | Фамилия |
| `order[firstName]` | `string` | Имя |
| `order[patronymic]` | `string` | Отчество |
| `order[phone]` | `string` | Телефон |
| `order[additionalPhone]` | `string` | Дополнительный телефон |
| `order[email]` | `string` | E-mail |
| `order[call]` | `boolean` | Требуется позвонить |
| `order[expired]` | `boolean` | Просрочен |
| `order[customerComment]` | `string` | Комментарий клиента |
| `order[managerComment]` | `string` | Комментарий оператора |
| `order[managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `order[customer]` | `object (Customer)object (CustomerCorporate)` | Клиент |
| `order[customer][type]` | `string` | Тип клиента |
| `order[customer][id]` | `integer` | ID клиента |
| `order[customer][externalId]` | `string` | Внешний ID клиента |
| `order[customer][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[customer][createdAt]` | `DateTime` | Создан |
| `order[customer][managerId]` | `integer` | Менеджер клиента |
| `order[customer][vip]` | `boolean` | Важный клиент |
| `order[customer][bad]` | `boolean` | Плохой клиент |
| `order[customer][site]` | `string` | Магазин, с которого пришел клиент |
| `order[customer][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[customer][contragent][contragentType]` | `string` | Тип контрагента |
| `order[customer][contragent][legalName]` | `string` | Полное наименование |
| `order[customer][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[customer][contragent][INN]` | `string` | ИНН |
| `order[customer][contragent][OKPO]` | `string` | ОКПО |
| `order[customer][contragent][KPP]` | `string` | КПП |
| `order[customer][contragent][OGRN]` | `string` | ОГРН |
| `order[customer][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[customer][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[customer][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[customer][contragent][BIK]` | `string` | БИК |
| `order[customer][contragent][bank]` | `string` | Банк |
| `order[customer][contragent][bankAddress]` | `string` | Адрес банка |
| `order[customer][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[customer][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[customer][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[customer][tags][][color]` | `string` |     |
| `order[customer][tags][][name]` | `string` |     |
| `order[customer][tags][][colorCode]` | `string` |     |
| `order[customer][tags][][attached]` | `boolean` |     |
| `order[customer][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[customer][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[customer][personalDiscount]` | `double` | Персональная скидка |
| `order[customer][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[customer][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[customer][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[customer][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[customer][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[customer][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[customer][ordersCount]` | `integer` | Количество заказов |
| `order[customer][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[customer][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[customer][address][id]` | `integer` | ID адреса |
| `order[customer][address][index]` | `string` | Индекс |
| `order[customer][address][countryIso]` | `string` | ISO код страны |
| `order[customer][address][region]` | `string` | Регион |
| `order[customer][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[customer][address][city]` | `string` | Город |
| `order[customer][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[customer][address][cityType]` | `string` | Тип населенного пункта |
| `order[customer][address][street]` | `string` | Улица |
| `order[customer][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[customer][address][streetType]` | `string` | Тип улицы |
| `order[customer][address][building]` | `string` | Дом |
| `order[customer][address][flat]` | `string` | Номер квартиры/офиса |
| `order[customer][address][floor]` | `integer` | Этаж |
| `order[customer][address][block]` | `integer` | Подъезд |
| `order[customer][address][house]` | `string` | Строение |
| `order[customer][address][housing]` | `string` | Корпус |
| `order[customer][address][metro]` | `string` | Метро |
| `order[customer][address][notes]` | `string` | Примечания к адресу |
| `order[customer][address][text]` | `string` | Адрес в текстовом виде |
| `order[customer][address][externalId]` | `string` | Внешний ID |
| `order[customer][address][name]` | `string` | Наменование адреса |
| `order[customer][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[customer][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[customer][segments][][code]` | `string` | Символьный код |
| `order[customer][segments][][name]` | `string` | Название сегмента |
| `order[customer][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[customer][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[customer][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[customer][segments][][active]` | `boolean` | Активность сегмента |
| `order[customer][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[customer][firstName]` | `string` | Имя |
| `order[customer][lastName]` | `string` | Фамилия |
| `order[customer][patronymic]` | `string` | Отчество |
| `order[customer][sex]` | `string` | Пол |
| `order[customer][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[customer][email]` | `string` | E-mail |
| `order[customer][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[customer][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[customer][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[customer][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[customer][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[customer][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[customer][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[customer][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[customer][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[customer][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[customer][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[customer][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[customer][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[customer][phones][][number]` | `string` | Номер телефона |
| `order[customer][birthday]` | `DateTime` | День рождения |
| `order[customer][source]` | `object (SerializedSource)` | Источник клиента |
| `order[customer][source][source]` | `string` | Источник |
| `order[customer][source][medium]` | `string` | Канал |
| `order[customer][source][campaign]` | `string` | Кампания |
| `order[customer][source][keyword]` | `string` | Ключевое слово |
| `order[customer][source][content]` | `string` | Содержание кампании |
| `order[customer][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[customer][mgCustomers][][id]` | `integer` | ID клиента |
| `order[customer][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[customer][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[customer][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[customer][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[customer][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[customer][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[customer][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[customer][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[customer][photoUrl]` | `string` | URL фотографии |
| `order[contact]` | `object (Customer)` | Контактное лицо |
| `order[contact][type]` | `string` | Тип клиента |
| `order[contact][id]` | `integer` | ID клиента |
| `order[contact][externalId]` | `string` | Внешний ID клиента |
| `order[contact][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[contact][createdAt]` | `DateTime` | Создан |
| `order[contact][managerId]` | `integer` | Менеджер клиента |
| `order[contact][vip]` | `boolean` | Важный клиент |
| `order[contact][bad]` | `boolean` | Плохой клиент |
| `order[contact][site]` | `string` | Магазин, с которого пришел клиент |
| `order[contact][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[contact][contragent][contragentType]` | `string` | Тип контрагента |
| `order[contact][contragent][legalName]` | `string` | Полное наименование |
| `order[contact][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contact][contragent][INN]` | `string` | ИНН |
| `order[contact][contragent][OKPO]` | `string` | ОКПО |
| `order[contact][contragent][KPP]` | `string` | КПП |
| `order[contact][contragent][OGRN]` | `string` | ОГРН |
| `order[contact][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contact][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contact][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contact][contragent][BIK]` | `string` | БИК |
| `order[contact][contragent][bank]` | `string` | Банк |
| `order[contact][contragent][bankAddress]` | `string` | Адрес банка |
| `order[contact][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contact][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[contact][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[contact][tags][][color]` | `string` |     |
| `order[contact][tags][][name]` | `string` |     |
| `order[contact][tags][][colorCode]` | `string` |     |
| `order[contact][tags][][attached]` | `boolean` |     |
| `order[contact][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[contact][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[contact][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contact][personalDiscount]` | `double` | Персональная скидка |
| `order[contact][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[contact][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[contact][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[contact][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[contact][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[contact][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[contact][ordersCount]` | `integer` | Количество заказов |
| `order[contact][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[contact][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[contact][address][id]` | `integer` | ID адреса |
| `order[contact][address][index]` | `string` | Индекс |
| `order[contact][address][countryIso]` | `string` | ISO код страны |
| `order[contact][address][region]` | `string` | Регион |
| `order[contact][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[contact][address][city]` | `string` | Город |
| `order[contact][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[contact][address][cityType]` | `string` | Тип населенного пункта |
| `order[contact][address][street]` | `string` | Улица |
| `order[contact][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[contact][address][streetType]` | `string` | Тип улицы |
| `order[contact][address][building]` | `string` | Дом |
| `order[contact][address][flat]` | `string` | Номер квартиры/офиса |
| `order[contact][address][floor]` | `integer` | Этаж |
| `order[contact][address][block]` | `integer` | Подъезд |
| `order[contact][address][house]` | `string` | Строение |
| `order[contact][address][housing]` | `string` | Корпус |
| `order[contact][address][metro]` | `string` | Метро |
| `order[contact][address][notes]` | `string` | Примечания к адресу |
| `order[contact][address][text]` | `string` | Адрес в текстовом виде |
| `order[contact][address][externalId]` | `string` | Внешний ID |
| `order[contact][address][name]` | `string` | Наменование адреса |
| `order[contact][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[contact][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[contact][segments][][code]` | `string` | Символьный код |
| `order[contact][segments][][name]` | `string` | Название сегмента |
| `order[contact][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[contact][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[contact][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[contact][segments][][active]` | `boolean` | Активность сегмента |
| `order[contact][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[contact][firstName]` | `string` | Имя |
| `order[contact][lastName]` | `string` | Фамилия |
| `order[contact][patronymic]` | `string` | Отчество |
| `order[contact][sex]` | `string` | Пол |
| `order[contact][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[contact][email]` | `string` | E-mail |
| `order[contact][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[contact][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[contact][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[contact][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[contact][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[contact][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[contact][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[contact][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[contact][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[contact][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[contact][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[contact][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[contact][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[contact][phones][][number]` | `string` | Номер телефона |
| `order[contact][birthday]` | `DateTime` | День рождения |
| `order[contact][source]` | `object (SerializedSource)` | Источник клиента |
| `order[contact][source][source]` | `string` | Источник |
| `order[contact][source][medium]` | `string` | Канал |
| `order[contact][source][campaign]` | `string` | Кампания |
| `order[contact][source][keyword]` | `string` | Ключевое слово |
| `order[contact][source][content]` | `string` | Содержание кампании |
| `order[contact][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[contact][mgCustomers][][id]` | `integer` | ID клиента |
| `order[contact][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[contact][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[contact][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[contact][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[contact][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[contact][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[contact][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[contact][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[contact][photoUrl]` | `string` | URL фотографии |
| `order[company]` | `object (Company)` | Компания |
| `order[company][id]` | `integer` | ID компании |
| `order[company][externalId]` | `string` | Внешний ID компании |
| `order[company][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `order[company][customer][site]` | `string` | Символьный код магазина |
| `order[company][customer][id]` | `integer` | Внутренний ID клиента |
| `order[company][customer][externalId]` | `string` | Внешний ID клиента |
| `order[company][customer][type]` | `string` | Тип клиента |
| `order[company][active]` | `boolean` | Активность |
| `order[company][name]` | `string` | Наименование |
| `order[company][brand]` | `string` | Бренд |
| `order[company][site]` | `string` | Сайт компании |
| `order[company][createdAt]` | `DateTime` | Дата создания |
| `order[company][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `order[company][contragent][contragentType]` | `string` | Тип контрагента |
| `order[company][contragent][legalName]` | `string` | Полное наименование |
| `order[company][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[company][contragent][INN]` | `string` | ИНН |
| `order[company][contragent][OKPO]` | `string` | ОКПО |
| `order[company][contragent][KPP]` | `string` | КПП |
| `order[company][contragent][OGRN]` | `string` | ОГРН |
| `order[company][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[company][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[company][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[company][contragent][BIK]` | `string` | БИК |
| `order[company][contragent][bank]` | `string` | Банк |
| `order[company][contragent][bankAddress]` | `string` | Адрес банка |
| `order[company][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[company][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[company][address]` | `object (CustomerAddress)` | Адрес |
| `order[company][address][id]` | `integer` | ID адреса |
| `order[company][address][index]` | `string` | Индекс |
| `order[company][address][countryIso]` | `string` | ISO код страны |
| `order[company][address][region]` | `string` | Регион |
| `order[company][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[company][address][city]` | `string` | Город |
| `order[company][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[company][address][cityType]` | `string` | Тип населенного пункта |
| `order[company][address][street]` | `string` | Улица |
| `order[company][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[company][address][streetType]` | `string` | Тип улицы |
| `order[company][address][building]` | `string` | Дом |
| `order[company][address][flat]` | `string` | Номер квартиры/офиса |
| `order[company][address][floor]` | `integer` | Этаж |
| `order[company][address][block]` | `integer` | Подъезд |
| `order[company][address][house]` | `string` | Строение |
| `order[company][address][housing]` | `string` | Корпус |
| `order[company][address][metro]` | `string` | Метро |
| `order[company][address][notes]` | `string` | Примечания к адресу |
| `order[company][address][text]` | `string` | Адрес в текстовом виде |
| `order[company][address][externalId]` | `string` | Внешний ID |
| `order[company][address][name]` | `string` | Наменование адреса |
| `order[company][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[company][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[company][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[company][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[company][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[company][ordersCount]` | `integer` | Количество заказов |
| `order[company][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contragent]` | `object (OrderContragent)` | Реквизиты |
| `order[contragent][contragentType]` | `string` | Тип контрагента |
| `order[contragent][legalName]` | `string` | Полное наименование |
| `order[contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contragent][INN]` | `string` | ИНН |
| `order[contragent][OKPO]` | `string` | ОКПО |
| `order[contragent][KPP]` | `string` | КПП |
| `order[contragent][OGRN]` | `string` | ОГРН |
| `order[contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contragent][BIK]` | `string` | БИК |
| `order[contragent][bank]` | `string` | Банк |
| `order[contragent][bankAddress]` | `string` | Адрес банка |
| `order[contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][code]` | `string` | Код типа доставки |
| `order[delivery][integrationCode]` | `string` | Интеграционный код типа доставки |
| `order[delivery][data]` | `object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData)` | Данные службы доставки, подключенной через API |
| `order[delivery][data][externalId]` | `string` | Идентификатор в службе доставки |
| `order[delivery][data][trackNumber]` | `string` | Номер отправления (поле `deprecated` на запись) |
| `order[delivery][data][status]` | `string` | Код статуса доставки |
| `order[delivery][data][locked]` | `boolean` | Не синхронизировать со службой доставки |
| `order[delivery][data][pickuppointAddress]` | `string` | Адрес пункта самовывоза |
| `order[delivery][data][days]` | `string` | Ориентировочный срок доставки |
| `order[delivery][data][statusText]` | `string` | Наименование статуса доставки |
| `order[delivery][data][statusDate]` | `DateTime` | Дата статуса доставки |
| `order[delivery][data][tariff]` | `string` | Код тарифа |
| `order[delivery][data][tariffName]` | `string` | Наименование тарифа |
| `order[delivery][data][pickuppointId]` | `string` | Идентификатор пункта самовывоза |
| `order[delivery][data][pickuppointSchedule]` | `string` | Режим работы пункта самовывоза |
| `order[delivery][data][pickuppointPhone]` | `string` | Телефон пункта самовывоза |
| `order[delivery][data][payerType]` | `string` | Плательщик за доставку |
| `order[delivery][data][statusComment]` | `string` | Комментарий к статусу доставки |
| `order[delivery][data][cost]` | `float` | Стоимость доставки, полученная из службы доставки (в валюте объекта) |
| `order[delivery][data][minTerm]` | `integer` | Минимальный срок доставки |
| `order[delivery][data][maxTerm]` | `integer` | Максимальный срок доставки |
| `order[delivery][data][shipmentpointId]` | `string` | Идентификатор терминала отгрузки |
| `order[delivery][data][shipmentpointName]` | `string` | Наименование терминала отгрузки |
| `order[delivery][data][shipmentpointAddress]` | `string` | Адрес терминала отгрузки |
| `order[delivery][data][shipmentpointSchedule]` | `string` | Режим работы терминала отгрузки |
| `order[delivery][data][shipmentpointPhone]` | `string` | Телефон терминала отгрузки |
| `order[delivery][data][shipmentpointCoordinateLatitude]` | `string` | Координаты терминала отгрузки, широта |
| `order[delivery][data][shipmentpointCoordinateLongitude]` | `string` | Координаты терминала отгрузки, долгота |
| `order[delivery][data][pickuppointName]` | `string` | Наименование пункта самовывоза |
| `order[delivery][data][pickuppointCoordinateLatitude]` | `string` | Координаты ПВЗ, широта |
| `order[delivery][data][pickuppointCoordinateLongitude]` | `string` | Координаты ПВЗ, долгота |
| `order[delivery][data][extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `order[delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |
| `order[delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][itemDeclaredValues][][value]` | `double` | Объявленная стоимость товара |
| `order[delivery][data][packages][]` | `array of objects (Package)` | Упаковки |
| `order[delivery][data][packages][][packageId]` | `string` | Идентификатор упаковки |
| `order[delivery][data][packages][][weight]` | `double` | Вес г. |
| `order[delivery][data][packages][][length]` | `integer` | Длина мм. |
| `order[delivery][data][packages][][width]` | `integer` | Ширина мм. |
| `order[delivery][data][packages][][height]` | `integer` | Высота мм. |
| `order[delivery][data][packages][][items][]` | `array of objects (PackageItem)` | Содержимое упаковки |
| `order[delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][packages][][items][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][packages][][items][][quantity]` | `double` | Количество товара в упаковке |
| `order[delivery][service]` | `object (SerializedDeliveryService)` |     |
| `order[delivery][service][name]` | `string` | Название |
| `order[delivery][service][code]` | `string` | Символьный код |
| `order[delivery][service][active]` | `boolean` | Статус активности |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[delivery][netCost]` | `double` | Себестоимость доставки |
| `order[delivery][date]` | `DateTime` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | Время "до" |
| `order[delivery][time][custom]` | `string` | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` | Адрес доставки |
| `order[delivery][address][index]` | `string` | Индекс |
| `order[delivery][address][countryIso]` | `string` | ISO код страны |
| `order[delivery][address][region]` | `string` | Регион |
| `order[delivery][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` | Город |
| `order[delivery][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` | Тип населенного пункта |
| `order[delivery][address][street]` | `string` | Улица |
| `order[delivery][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` | Тип улицы |
| `order[delivery][address][building]` | `string` | Дом |
| `order[delivery][address][flat]` | `string` | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` | Этаж |
| `order[delivery][address][block]` | `integer` | Подъезд |
| `order[delivery][address][house]` | `string` | Строение |
| `order[delivery][address][housing]` | `string` | Корпус |
| `order[delivery][address][metro]` | `string` | Метро |
| `order[delivery][address][notes]` | `string` | Примечания к адресу |
| `order[delivery][address][text]` | `string` | Адрес в текстовом виде |
| `order[delivery][vatRate]` | `string` | Ставка НДС |
| `order[site]` | `string` | Магазин |
| `order[status]` | `string` | Статус заказа |
| `order[statusComment]` | `string` | Комментарий к последнему изменению статуса |
| `order[source]` | `object (SerializedSource)` | Источник заказа |
| `order[source][source]` | `string` | Источник |
| `order[source][medium]` | `string` | Канал |
| `order[source][campaign]` | `string` | Кампания |
| `order[source][keyword]` | `string` | Ключевое слово |
| `order[source][content]` | `string` | Содержание кампании |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][markingCodes][]` | `array of strings` | Коды маркировки |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][createdAt]` | `DateTime` | Дата создания позиции в системе |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][status]` | `string` | Статус позиции в заказе |
| `order[items][][comment]` | `string` | Комментарий к позиции в заказе |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][displayName]` | `string` | Название SKU |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `order[items][][offer][name]` | `string` | Название |
| `order[items][][offer][article]` | `string` | Артикул |
| `order[items][][offer][vatRate]` | `string` | Ставка НДС |
| `order[items][][offer][properties]` | `array` | Свойства SKU |
| `order[items][][offer][unit]` | `object (Unit)` | Единица измерения |
| `order[items][][offer][unit][code]` | `string` | Символьный код |
| `order[items][][offer][unit][name]` | `string` | Название |
| `order[items][][offer][unit][sym]` | `string` | Краткое обозначение |
| `order[items][][offer][barcode]` | `string` | Штрих-код |
| `order[items][][isCanceled]` | `boolean` | Данная позиция в заказе является отменной |
| `order[items][][properties]` | `array` | \[массив\] Дополнительные свойства позиции в заказе |
| `order[items][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `order[items][][ordering]` | `integer` | Порядок |
| `order[fullPaidAt]` | `DateTime` | Дата полной оплаты |
| `order[payments][]` | `array of objects (Payment)` | Платежи |
| `order[payments][][id]` | `integer` | Внутренний ID |
| `order[payments][][status]` | `string` | Статус оплаты |
| `order[payments][][type]` | `string` | Тип оплаты |
| `order[payments][][externalId]` | `string` | Внешний ID платежа |
| `order[payments][][amount]` | `double` | Сумма платежа (в валюте объекта) |
| `order[payments][][paidAt]` | `DateTime` | Дата оплаты |
| `order[payments][][comment]` | `string` | Комментарий |
| `order[fromApi]` | `boolean` | Заказ поступил через API |
| `order[weight]` | `double` | Вес |
| `order[length]` | `integer` | Длина |
| `order[width]` | `integer` | Ширина |
| `order[height]` | `integer` | Высота |
| `order[shipmentStore]` | `string` | Склад отгрузки |
| `order[shipmentDate]` | `DateTime` | Дата отгрузки |
| `order[shipped]` | `boolean` | Заказ отгружен |
| `order[links][]` | `array of objects (OrderLink)` | Связь заказов |
| `order[links][][order]` | `object (LinkedOrder)` | Связанный заказ |
| `order[links][][order][id]` | `integer` | ID связанного заказа |
| `order[links][][order][number]` | `string` | Номер связанного заказа |
| `order[links][][order][externalId]` | `string` | Внешний ID связанного заказа |
| `order[links][][createdAt]` | `DateTime` | Дата/время создания связи с заказом |
| `order[links][][comment]` | `string` | Комментарий |
| `order[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[clientId]` | `string` | Метка клиента Google Analytics |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заказ создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании заказа</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/fix-external-ids

Массовая запись внешних ID заказов

Для доступа к методу необходимо разрешение `order_write`.

Данный метод полезен в случае обратной синхронизации заказов, которые исходно оформлены в системе.

Рекомендуется реализовывать следующей сценарий обратной синхронизации заказов между интернет-магазином и системой. Интернет-магазин периодически опрашивает метод `/api/v*/orders/history`. На основе получаемых данных магазин применяет изменения к существующим заказам, а также создает новые заказы, исходно оформленные в системе. При создании заказов в магазине формируются собственные ID заказов (`externalId` заказов в нотации системы). Сразу после создания заказов интернет-магазин вызывает метод `/api/v*/orders/fix-external-ids`, сохраняя в системе собственные ID заказов.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `orders[]` | `array of objects (FixExternalRow)` |     | Идентификаторы загруженных объектов |
| `orders[][id]` | `digit` | `{not blank}{range: {>=1, <=2147483647}}}` | Внутренний ID |
| `orders[][externalId]` | `string` | `{not blank}` | Внешний ID |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/history

Получение истории изменений заказов

Для доступа к методу необходимо разрешение `order_read`.

Возвращает изменения в заказах, произведенные в указанный диапазон дат (используя фильтры `filter[startDate]` и `filter[endDate]`), либо инкрементальные изменения (используя `filter[sinceId]`).

При реализации постоянной трансляции изменений во внешнюю систему ​**рекомендуется**​ использовать подход с забором инкрементальных изменений через `filter[sinceId]` передавая `id` последней полученной записи истории.

Для записей создания и удаления заказа и товара в заказе возвращается полный набор полей в соответствующих ключах `order` или `item`.

Добавление товара в заказ отражается записью истории с `fieldName` равным `order_product`, при этом полная информация о товаре в заказе доступна в контексте `item`, а в `newValue` указываются только поля-идентификаторы.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке. Для постраничного перебора записей истории ​**необходимо**​ использовать `filter[sinceId]`. Параметр `page` использовать не рекомендуется.

Более подробно о работе с историей вы можете ознакомиться в [отдельной статье документации](https://docs.retailcrm.ru/Developers/API/APIFeatures/WorkingHistoryAPI), а значения `fieldName` указаны в [Справочнике объектов](https://docs.retailcrm.ru/Developers/Automation/ObjectReference).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (OrderHistoryFilterV4Type)` |     |     |
| `filter[orderId]` | `integer` | `{range: {>=0, <=4294967295}}{not blank}}` | ID заказа |
| `filter[sinceId]` | `integer` | `{range: {>=0, <=4294967295}}{not blank}}` | Начиная с ID истории заказов |
| `filter[orderExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID заказа |
| `filter[startDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (от) |
| `filter[endDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `generatedAt` | `DateTime` | Время формирования ответа |
| `history[]` | `array of objects (OrderHistory)` |     |
| `history[][id]` | `integer` | Внутренний идентификатор записи в истории |
| `history[][createdAt]` | `DateTime` | Дата внесения изменения |
| `history[][created]` | `boolean` | Признак создания сущности |
| `history[][deleted]` | `boolean` | Признак удаления сущности |
| `history[][source]` | `string` | Источник изменения |
| `history[][user]` | `object (User)` | Пользователь |
| `history[][user][id]` | `integer` | ID пользователя |
| `history[][field]` | `string` | Имя изменившегося поля |
| `history[][oldValue]` | `custom handler result for (mixed)` | Старое значение свойства |
| `history[][newValue]` | `custom handler result for (mixed)` | Новое значение свойства |
| `history[][apiKey]` | `object (ApiKey)` | Информация о ключе api, использовавшемся для этого изменения |
| `history[][apiKey][current]` | `boolean` | Изменение было сделано с помощью ключа, используемого в данный момент |
| `history[][apiKey][id]` | `integer` | ID API-ключа |
| `history[][order]` | `object (Order)` | Заказ |
| `history[][order][id]` | `integer` | ID заказа |
| `history[][order][externalId]` | `string` | Внешний ID заказа |
| `history[][order][managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `history[][order][site]` | `string` | Магазин |
| `history[][order][status]` | `string` | Статус заказа |
| `history[][ancestor]` | `object (Order)` | Информация о заказе из которого был создан текущий заказ |
| `history[][item]` | `object (OrderProduct)` | Позиция в заказе |
| `history[][item][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `history[][item][id]` | `integer` | ID позиции в заказе |
| `history[][item][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `history[][item][externalIds][][code]` | `string` | Код |
| `history[][item][externalIds][][value]` | `string` | Значение |
| `history[][item][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `history[][item][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `history[][item][discounts][][amount]` | `float` | Сумма скидки |
| `history[][item][offer]` | `object (Offer)` | Торговое предложение |
| `history[][item][offer][id]` | `integer` | ID торгового предложения |
| `history[][item][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `history[][item][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `history[][item][offer][properties][]` | `array of strings` | Свойства SKU |
| `history[][item][ordering]` | `integer` | Порядок |
| `history[][item][properties][]` | `array of strings` | \[массив\] Дополнительные свойства позиции в заказе |
| `history[][item][properties][][code]` | `string` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `history[][item][properties][][name]` | `string` | Имя свойства |
| `history[][item][properties][][value]` | `string` | Значение свойства |
| `history[][payment]` | `object (Payment)` | Платёж |
| `history[][payment][id]` | `integer` | Внутренний ID |
| `history[][payment][type]` | `string` | Тип оплаты |
| `history[][payment][externalId]` | `string` | Внешний ID платежа |
| `history[][combinedTo]` | `object (Order)` | Информация о заказе который получился после объединения с текущим заказом |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/links/create

Создание связи между заказами

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет связать несколько заказов между собой.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина. Указывается в случае указания заказов через externalId или number |
| `link` | `object (SerializedOrderLink)` |     |     |
| `link[comment]` | `string` | `{length: {max: 1000}}` | Комментарий |
| `link[orders][]` | `array of objects (SerializedEntityOrder)` |     | Заказ |
| `link[orders][][id]` | `integer` |     | Внутренний ID заказа |
| `link[orders][][externalId]` | `string` |     | Внешний ID заказа |
| `link[orders][][number]` | `string` |     | Номер заказа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Связь между заказами успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании связи между заказами</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/loyalty/apply

Применение бонусов по программе лояльности

Для доступа к методу необходимо разрешение `order_write`.

Метод применения бонусов по программе лояльности. Если включена настройка бонусного счета программы лояльности "Подтверждать списание по СМС", то необходимо, чтобы в участии программы лояльности был указан номер телефона. На него будет выслано смс сообщение с подтверждением списания бонусов. Списание будет произведено только после подтверждения списания кодом из смс.

Для повторной отправки смс следует вызвать этот метод еще раз с актуальными параметрами. Повторная отправка доступна через 60 секунд. Срок жизни кода из смс - 5 минут.

В случае, если отправка смс для подтверждения списания бонусов не требуется, бонусы будут списаны, и в ответе вернется объект `SerializedLoyaltyOrder`. В случае подтверждения по смс будет возвращен объект `SmsVerification`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `order` | `object (SerializedEntityOrder)` |     | Заказ |
| `order[id]` | `integer` |     | Внутренний ID заказа |
| `order[externalId]` | `string` |     | Внешний ID заказа |
| `order[number]` | `string` |     | Номер заказа |
| `order[applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |
| `bonuses` | `float` |     | Количество бонусов для списания |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `order` | `object (SerializedLoyaltyOrder)` |     |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[currency]` | `string` | Валюта |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyAccount]` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `order[loyaltyAccount][id]` | `integer` | ID участия |
| `order[loyaltyAccount][amount]` | `float` | Количество активных бонусов |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[customer]` | `object (Customer)` | Клиент |
| `order[customer][id]` | `integer` | ID клиента |
| `order[customer][externalId]` | `string` | Внешний ID клиента |
| `order[customer][personalDiscount]` | `double` | Персональная скидка |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[site]` | `string` | Магазин |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `verification` | `object (SmsVerification)` | SMS-верификация |
| `verification[createdAt]` | `DateTime` | Дата создания (Y-m-d H:i:s) |
| `verification[expiredAt]` | `DateTime` | Дата окончания срока жизни (Y-m-d H:i:s) |
| `verification[verifiedAt]` | `DateTime` | Дата успешной верификации (Y-m-d H:i:s) |
| `verification[checkId]` | `string` | Идентификатор проверки кода |
| `verification[actionType]` | `string` | Тип действия |

### POST /api/v5/orders/loyalty/cancel-bonus-operations

Отмена бонусных операций по программе лояльности

Для доступа к методу необходимо разрешение `order_write`.

Метод отменяет совершенные действия с бонусами в заказе. При отмене клиенту вернутся списанные в заказе бонусы и спишутся начисленные.

Если в заказе есть только списание или только начисление бонусов, то будет произведена отмена только совершенной операции.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `order` | `object (SerializedEntityOrder)` |     | Заказ |
| `order[id]` | `integer` |     | Внутренний ID заказа |
| `order[externalId]` | `string` |     | Внешний ID заказа |
| `order[number]` | `string` |     | Номер заказа |
| `order[applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `order` | `object (Order)` | Заказ |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[currency]` | `string` | Валюта |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyAccount]` | `object (LoyaltyAccount)` | Участие в программе лояльности |
| `order[loyaltyAccount][id]` | `integer` | ID участия |
| `order[loyaltyAccount][amount]` | `float` | Количество активных бонусов |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[site]` | `string` | Магазин |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |

### POST /api/v5/orders/payments/create

Добавление платежа

Для доступа к методу необходимо разрешение `order_write`.

Метод добавляет платеж к заказу и возвращает внутренний ID созданного платежа.

Указать к какому заказу должен быть привязан платёж, **необходимо** установив значения одного из следующих полей:

- `payment[order][id]` - внутренний ID заказа
- `payment[order][externalId]` - внешний ID заказа
- `payment[order][number]` - номер заказа

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `payment` | `object (SerializedPayment)` |     | Платёж |
| `payment[externalId]` | `string` |     | Внешний ID платежа |
| `payment[amount]` | `double` |     | Сумма платежа (в валюте объекта) |
| `payment[paidAt]` | `DateTime` |     | Дата оплаты |
| `payment[comment]` | `string` |     | Комментарий |
| `payment[order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `payment[order][id]` | `integer` |     | Внутренний ID заказа |
| `payment[order][externalId]` | `string` |     | Внешний ID заказа |
| `payment[order][number]` | `string` |     | Номер заказа |
| `payment[type]` | `string` |     | Тип оплаты |
| `payment[status]` | `string` |     | Статус оплаты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID добавленного платежа |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Платеж добавлен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при добавлении платежа</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/payments/{id}/delete

Удаление платежа

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет удалить платёж.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | Внутренний ID удаляемого платежа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Платёж успешно удалён</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при удалении платежа</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Платёж не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/payments/{id}/edit

Редактирование платежа

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет вносить изменения в платёж.

Изменение `payment[externalId]` доступно только при обращении к платежу по `id` (by=id), в противном случае (by=externalId) переданное значение будет проигнорировано.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре id: внутренний (by=id) или внешний (by=externalId) ID платежа. По умолчанию id. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае обращения к платежу по externalId (by=externalId) |
| `payment` | `object (SerializedPayment)` |     | Платёж |
| `payment[externalId]` | `string` |     | Внешний ID платежа |
| `payment[amount]` | `double` |     | Сумма платежа (в валюте объекта) |
| `payment[paidAt]` | `DateTime` |     | Дата оплаты |
| `payment[comment]` | `string` |     | Комментарий |
| `payment[status]` | `string` |     | Статус оплаты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID изменённого платежа |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Платёж изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении платежа</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Платёж не найден</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/statuses

Получение списка статусов заказов

Для доступа к методу необходимо разрешение `order_read`.

Возвращает статусы (и группы статусов) для заказов, `id` или `externalId` которых указаны в параметрах запроса `ids[]` и `externalIds[]`.

Можно указывать суммарно до 500 идентификаторов. Если указаны и `ids[]`, и `externalIds[]`, то производится поиск заказов по всем указанным идентификаторам.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `ids[]` | `array` |     | ID (внутренние идентификаторы) заказов |
| `externalIds[]` | `array` |     | externalId (внешние идентификаторы) заказов |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `orders[]` | `array of objects (OrderStatusItem)` | Статусы заказов |
| `orders[][id]` | `integer` | ID заказа |
| `orders[][externalId]` | `string` | Внешний ID заказа |
| `orders[][status]` | `string` | Статус заказа |
| `orders[][group]` | `string` | Группа статуса заказа |
| `orders[][number]` | `string` | Номер заказа |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/upload

Пакетная загрузка заказов

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет загружать пакетно до 50 заказов.

Подробную информацию по формату данных см. в описании метода `/api/v*/orders/create`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина, к которому относятся загружаемые заказы |
| `orders[]` | `array of objects (SerializedOrder)` |     |     |
| `orders[][number]` | `string` |     | Номер заказа |
| `orders[][externalId]` | `string` |     | Внешний ID заказа |
| `orders[][privilegeType]` | `string` |     | Тип привилегии |
| `orders[][countryIso]` | `string` |     | ISO код страны |
| `orders[][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата оформления заказа |
| `orders[][statusUpdatedAt]` | `DateTime` | `Y-m-d H:i:s` | Дата последнего изменения статуса |
| `orders[][discountManualAmount]` | `double` |     | Денежная скидка на весь заказ (в валюте объекта) |
| `orders[][discountManualPercent]` | `double` |     | Процентная скидка на весь заказ |
| `orders[][mark]` | `integer` |     | Оценка заказа |
| `orders[][markDatetime]` | `DateTime` | `Y-m-d H:i:s` | Дата и время получение оценки от покупателя |
| `orders[][lastName]` | `string` |     | Фамилия |
| `orders[][firstName]` | `string` |     | Имя |
| `orders[][patronymic]` | `string` |     | Отчество |
| `orders[][phone]` | `string` |     | Телефон |
| `orders[][additionalPhone]` | `string` |     | Дополнительный телефон |
| `orders[][email]` | `string` |     | E-mail |
| `orders[][call]` | `boolean` |     | Требуется позвонить |
| `orders[][expired]` | `boolean` |     | Просрочен |
| `orders[][customerComment]` | `string` |     | Комментарий клиента |
| `orders[][managerComment]` | `string` |     | Комментарий оператора |
| `orders[][contragent]` | `object (OrderContragent)` |     | Реквизиты |
| `orders[][contragent][contragentType]` | `string` |     | Тип контрагента |
| `orders[][contragent][legalName]` | `string` |     | Полное наименование |
| `orders[][contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `orders[][contragent][INN]` | `string` |     | ИНН |
| `orders[][contragent][OKPO]` | `string` |     | ОКПО |
| `orders[][contragent][KPP]` | `string` |     | КПП |
| `orders[][contragent][OGRN]` | `string` |     | ОГРН |
| `orders[][contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `orders[][contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `orders[][contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `orders[][contragent][BIK]` | `string` |     | БИК |
| `orders[][contragent][bank]` | `string` |     | Банк |
| `orders[][contragent][bankAddress]` | `string` |     | Адрес банка |
| `orders[][contragent][corrAccount]` | `string` |     | Корр. счёт |
| `orders[][contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `orders[][statusComment]` | `string` |     | Комментарий к последнему изменению статуса |
| `orders[][weight]` | `double` |     | Вес |
| `orders[][length]` | `integer` |     | Длина |
| `orders[][width]` | `integer` |     | Ширина |
| `orders[][height]` | `integer` |     | Высота |
| `orders[][shipmentDate]` | `DateTime` | `Y-m-d` | Дата отгрузки |
| `orders[][shipped]` | `boolean` |     | Заказ отгружен |
| `orders[][dialogId]` | `object (MGDialog)` |     | Идентификатор диалога Чатов |
| `orders[][customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `orders[][orderType]` | `string` |     | Тип заказа |
| `orders[][orderMethod]` | `string` |     | Способ оформления |
| `orders[][customer]` | `object (SerializedRelationCustomer)` |     | Клиент |
| `orders[][customer][id]` | `integer` |     | Внутренний ID клиента |
| `orders[][customer][externalId]` | `string` |     | Внешний ID клиента |
| `orders[][customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `orders[][customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `orders[][customer][type]` | `string` |     | Тип клиента (передаётся когда нужно создать нового клиента) |
| `orders[][customer][nickName]` | `string` |     | Наименование корпоративного клиента (передаётся когда нужно создать нового корпоративного клиента) |
| `orders[][contact]` | `object (SerializedRelationAbstractCustomer)` |     | Контактное лицо |
| `orders[][contact][id]` | `integer` |     | Внутренний ID клиента |
| `orders[][contact][externalId]` | `string` |     | Внешний ID клиента |
| `orders[][contact][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `orders[][contact][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `orders[][company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `orders[][company][id]` | `integer` |     | ID  |
| `orders[][company][externalId]` | `string` |     | Внешний ID |
| `orders[][managerId]` | `integer` |     | Менеджер, прикрепленный к заказу |
| `orders[][status]` | `string` |     | Статус заказа |
| `orders[][items][]` | `array of objects (SerializedOrderProduct)` |     |     |
| `orders[][items][][markingCodes][]` | `array of strings` |     | Коды маркировки |
| `orders[][items][][initialPrice]` | `double` |     | Цена товара/SKU (в валюте объекта) |
| `orders[][items][][discountManualAmount]` | `double` |     | Денежная скидка на единицу товара (в валюте объекта) |
| `orders[][items][][discountManualPercent]` | `double` |     | Процентная скидка на единицу товара |
| `orders[][items][][vatRate]` | `string` |     | Ставка НДС |
| `orders[][items][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания позиции в системе |
| `orders[][items][][quantity]` | `float` |     | Количество |
| `orders[][items][][comment]` | `string` |     | Комментарий к позиции в заказе |
| `orders[][items][][properties][]` | `array` |     | \[массив\] Дополнительные свойства позиции в заказе |
| `orders[][items][][properties][][code]` | `string` | `{not blank}{match: /^[a-zA-Z0-9_][a-zA-Z0-9_\-:]*$/D}}` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `orders[][items][][properties][][name]` | `string` | `{not blank}` | Имя свойства |
| `orders[][items][][properties][][value]` | `string` | `{not blank}` | Значение свойства |
| `orders[][items][][purchasePrice]` | `double` |     | Закупочная цена (в базовой валюте) |
| `orders[][items][][ordering]` | `integer` |     | Порядок |
| `orders[][items][][offer]` | `object (SerializedOrderProductOffer)` |     | Торговое предложение |
| `orders[][items][][offer][id]` | `integer` |     | ID торгового предложения |
| `orders[][items][][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `orders[][items][][offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `orders[][items][][productName]` | `string` |     | Название товара |
| `orders[][items][][status]` | `string` |     | Статус позиции в заказе |
| `orders[][items][][priceType]` | `object (PriceType)` |     | Тип цены |
| `orders[][items][][priceType][code]` | `string` |     | Код типа цены |
| `orders[][items][][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `orders[][items][][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `orders[][items][][externalIds][][code]` | `string` |     | Код |
| `orders[][items][][externalIds][][value]` | `string` |     | Значение |
| `orders[][delivery]` | `object (SerializedOrderDelivery)` |     | Данные о доставке |
| `orders[][delivery][code]` | `string` |     | Код типа доставки |
| `orders[][delivery][data]` | object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData) |     | Данные службы доставки, подключенной через API |
| `orders[][delivery][data][externalId]` | `string` |     | Идентификатор в службе доставки |
| `orders[][delivery][data][trackNumber]` | `string` |     | Номер отправления (поле `deprecated` на запись) |
| `orders[][delivery][data][locked]` | `boolean` |     | Не синхронизировать со службой доставки |
| `orders[][delivery][data][tariff]` | `string` |     | Код тарифа |
| `orders[][delivery][data][pickuppointId]` | `string` |     | Идентификатор пункта самовывоза |
| `orders[][delivery][data][payerType]` | `string` |     | Плательщик за доставку |
| `orders[][delivery][data][shipmentpointId]` | `string` |     | Идентификатор терминала отгрузки |
| `orders[][delivery][data][extraData]` | `array` |     | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `orders[][delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |     |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `orders[][delivery][data][itemDeclaredValues][][value]` | `double` |     | Объявленная стоимость товара |
| `orders[][delivery][data][packages][]` | `array of objects (Package)` |     | Упаковки |
| `orders[][delivery][data][packages][][packageId]` | `string` |     | Идентификатор упаковки |
| `orders[][delivery][data][packages][][weight]` | `double` |     | Вес г. |
| `orders[][delivery][data][packages][][length]` | `integer` |     | Длина мм. |
| `orders[][delivery][data][packages][][width]` | `integer` |     | Ширина мм. |
| `orders[][delivery][data][packages][][height]` | `integer` |     | Высота мм. |
| `orders[][delivery][data][packages][][items][]` | `array of objects (PackageItem)` |     | Содержимое упаковки |
| `orders[][delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `orders[][delivery][data][packages][][items][][quantity]` | `double` |     | Количество товара в упаковке |
| `orders[][delivery][service]` | `object (SerializedDeliveryService)` |     |     |
| `orders[][delivery][service][name]` | `string` |     | Название |
| `orders[][delivery][service][code]` | `string` |     | Символьный код |
| `orders[][delivery][service][active]` | `boolean` |     | Статус активности |
| `orders[][delivery][service][deliveryType]` | `string` |     | Тип доставки |
| `orders[][delivery][cost]` | `double` |     | Стоимость доставки |
| `orders[][delivery][netCost]` | `double` |     | Себестоимость доставки |
| `orders[][delivery][date]` | `DateTime` | `Y-m-d` | Дата доставки |
| `orders[][delivery][time]` | `object (TimeInterval)` |     | Информация о временном диапазоне |
| `orders[][delivery][time][from]` | `DateTime` | `H:i` | Время "с" |
| `orders[][delivery][time][to]` | `DateTime` | `H:i` | Время "до" |
| `orders[][delivery][time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `orders[][delivery][address]` | `object (OrderDeliveryAddress)` |     | Адрес доставки |
| `orders[][delivery][address][index]` | `string` |     | Индекс |
| `orders[][delivery][address][countryIso]` | `string` |     | ISO код страны |
| `orders[][delivery][address][region]` | `string` |     | Регион |
| `orders[][delivery][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `orders[][delivery][address][city]` | `string` |     | Город |
| `orders[][delivery][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `orders[][delivery][address][cityType]` | `string` |     | Тип населенного пункта |
| `orders[][delivery][address][street]` | `string` |     | Улица |
| `orders[][delivery][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `orders[][delivery][address][streetType]` | `string` |     | Тип улицы |
| `orders[][delivery][address][building]` | `string` |     | Дом |
| `orders[][delivery][address][flat]` | `string` |     | Номер квартиры/офиса |
| `orders[][delivery][address][floor]` | `integer` |     | Этаж |
| `orders[][delivery][address][block]` | `integer` |     | Подъезд |
| `orders[][delivery][address][house]` | `string` |     | Строение |
| `orders[][delivery][address][housing]` | `string` |     | Корпус |
| `orders[][delivery][address][metro]` | `string` |     | Метро |
| `orders[][delivery][address][notes]` | `string` |     | Примечания к адресу |
| `orders[][delivery][address][text]` | `string` |     | Адрес в текстовом виде |
| `orders[][delivery][vatRate]` | `string` |     | Ставка НДС |
| `orders[][source]` | `object (SerializedSource)` |     | Источник заказа |
| `orders[][source][source]` | `string` |     | Источник |
| `orders[][source][medium]` | `string` |     | Канал |
| `orders[][source][campaign]` | `string` |     | Кампания |
| `orders[][source][keyword]` | `string` |     | Ключевое слово |
| `orders[][source][content]` | `string` |     | Содержание кампании |
| `orders[][shipmentStore]` | `string` |     | Склад отгрузки |
| `orders[][payments][]` | `array of objects (SerializedPayment)` |     | Платежи |
| `orders[][payments][][externalId]` | `string` |     | Внешний ID платежа |
| `orders[][payments][][amount]` | `double` |     | Сумма платежа (в валюте объекта) |
| `orders[][payments][][paidAt]` | `DateTime` | `Y-m-d H:i:s` | Дата оплаты |
| `orders[][payments][][comment]` | `string` |     | Комментарий |
| `orders[][payments][][type]` | `string` |     | Тип оплаты |
| `orders[][payments][][status]` | `string` |     | Статус оплаты |
| `orders[][loyaltyEventDiscountId]` | `integer` |     | ID скидки по событию программы лояльности |
| `orders[][applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |
| `orders[][isFromCart]` | `boolean` |     | Заказ создан из корзины |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `uploadedOrders[]` | `array of objects (FixExternalRow)` | Идентификаторы загруженных объектов |
| `uploadedOrders[][id]` | `integer` | Внутренний ID |
| `uploadedOrders[][externalId]` | `string` | Внешний ID |
| `failedOrders[]` | `array of objects (EntityWithExternalId)` | Идентификаторы незагруженных объектов |
| `failedOrders[][externalId]` | `string` | Внешний ID (при наличии) |
| `orders[]` | `array of objects (Order)` | Заказ |
| `orders[][slug]` | `custom handler result for (int)` | `deprecated` Символьный код |
| `orders[][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `orders[][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `orders[][summ]` | `double` | Сумма по товарам/услугам (в валюте объекта) |
| `orders[][currency]` | `string` | Валюта |
| `orders[][id]` | `integer` | ID заказа |
| `orders[][number]` | `string` | Номер заказа |
| `orders[][externalId]` | `string` | Внешний ID заказа |
| `orders[][orderType]` | `string` | Тип заказа |
| `orders[][orderMethod]` | `string` | Способ оформления |
| `orders[][privilegeType]` | `string` | Тип привилегии |
| `orders[][countryIso]` | `string` | ISO код страны |
| `orders[][createdAt]` | `DateTime` | Дата оформления заказа |
| `orders[][statusUpdatedAt]` | `DateTime` | Дата последнего изменения статуса |
| `orders[][totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `orders[][prepaySum]` | `double` | Оплаченная сумма (в валюте объекта) |
| `orders[][purchaseSumm]` | `double` | Общая стоимость закупки (в базовой валюте) |
| `orders[][personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `orders[][loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `orders[][loyaltyLevel][id]` | `integer` | ID уровня |
| `orders[][loyaltyLevel][name]` | `string` | Название уровня |
| `orders[][loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `orders[][loyaltyEventDiscount][id]` | `integer` | ID  |
| `orders[][mark]` | `integer` | Оценка заказа |
| `orders[][markDatetime]` | `DateTime` | Дата и время получение оценки от покупателя |
| `orders[][lastName]` | `string` | Фамилия |
| `orders[][firstName]` | `string` | Имя |
| `orders[][patronymic]` | `string` | Отчество |
| `orders[][phone]` | `string` | Телефон |
| `orders[][additionalPhone]` | `string` | Дополнительный телефон |
| `orders[][email]` | `string` | E-mail |
| `orders[][call]` | `boolean` | Требуется позвонить |
| `orders[][expired]` | `boolean` | Просрочен |
| `orders[][customerComment]` | `string` | Комментарий клиента |
| `orders[][managerComment]` | `string` | Комментарий оператора |
| `orders[][managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `orders[][customer]` | `object (Customer)object (CustomerCorporate)` | Клиент |
| `orders[][customer][type]` | `string` | Тип клиента |
| `orders[][customer][id]` | `integer` | ID клиента |
| `orders[][customer][externalId]` | `string` | Внешний ID клиента |
| `orders[][customer][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `orders[][customer][createdAt]` | `DateTime` | Создан |
| `orders[][customer][managerId]` | `integer` | Менеджер клиента |
| `orders[][customer][vip]` | `boolean` | Важный клиент |
| `orders[][customer][bad]` | `boolean` | Плохой клиент |
| `orders[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `orders[][customer][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `orders[][customer][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][customer][contragent][legalName]` | `string` | Полное наименование |
| `orders[][customer][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][customer][contragent][INN]` | `string` | ИНН |
| `orders[][customer][contragent][OKPO]` | `string` | ОКПО |
| `orders[][customer][contragent][KPP]` | `string` | КПП |
| `orders[][customer][contragent][OGRN]` | `string` | ОГРН |
| `orders[][customer][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][customer][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][customer][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][customer][contragent][BIK]` | `string` | БИК |
| `orders[][customer][contragent][bank]` | `string` | Банк |
| `orders[][customer][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][customer][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][customer][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][customer][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `orders[][customer][tags][][color]` | `string` |     |
| `orders[][customer][tags][][name]` | `string` |     |
| `orders[][customer][tags][][colorCode]` | `string` |     |
| `orders[][customer][tags][][attached]` | `boolean` |     |
| `orders[][customer][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `orders[][customer][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `orders[][customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][customer][personalDiscount]` | `double` | Персональная скидка |
| `orders[][customer][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `orders[][customer][discountCardNumber]` | `string` | Номер дисконтной карты |
| `orders[][customer][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][customer][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][customer][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][customer][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][customer][ordersCount]` | `integer` | Количество заказов |
| `orders[][customer][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][customer][address]` | `object (CustomerAddress)` | Адрес клиента |
| `orders[][customer][address][id]` | `integer` | ID адреса |
| `orders[][customer][address][index]` | `string` | Индекс |
| `orders[][customer][address][countryIso]` | `string` | ISO код страны |
| `orders[][customer][address][region]` | `string` | Регион |
| `orders[][customer][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][customer][address][city]` | `string` | Город |
| `orders[][customer][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][customer][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][customer][address][street]` | `string` | Улица |
| `orders[][customer][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][customer][address][streetType]` | `string` | Тип улицы |
| `orders[][customer][address][building]` | `string` | Дом |
| `orders[][customer][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][customer][address][floor]` | `integer` | Этаж |
| `orders[][customer][address][block]` | `integer` | Подъезд |
| `orders[][customer][address][house]` | `string` | Строение |
| `orders[][customer][address][housing]` | `string` | Корпус |
| `orders[][customer][address][metro]` | `string` | Метро |
| `orders[][customer][address][notes]` | `string` | Примечания к адресу |
| `orders[][customer][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][customer][address][externalId]` | `string` | Внешний ID |
| `orders[][customer][address][name]` | `string` | Наменование адреса |
| `orders[][customer][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `orders[][customer][segments][][id]` | `integer` | Внутренний ID сегмента |
| `orders[][customer][segments][][code]` | `string` | Символьный код |
| `orders[][customer][segments][][name]` | `string` | Название сегмента |
| `orders[][customer][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `orders[][customer][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `orders[][customer][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `orders[][customer][segments][][active]` | `boolean` | Активность сегмента |
| `orders[][customer][maturationTime]` | `integer` | Время «созревания», в секундах |
| `orders[][customer][firstName]` | `string` | Имя |
| `orders[][customer][lastName]` | `string` | Фамилия |
| `orders[][customer][patronymic]` | `string` | Отчество |
| `orders[][customer][sex]` | `string` | Пол |
| `orders[][customer][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `orders[][customer][email]` | `string` | E-mail |
| `orders[][customer][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `orders[][customer][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `orders[][customer][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `orders[][customer][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `orders[][customer][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `orders[][customer][customerSubscriptions][][subscription][name]` | `string` | Название |
| `orders[][customer][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `orders[][customer][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `orders[][customer][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `orders[][customer][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `orders[][customer][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `orders[][customer][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `orders[][customer][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `orders[][customer][phones][][number]` | `string` | Номер телефона |
| `orders[][customer][birthday]` | `DateTime` | День рождения |
| `orders[][customer][source]` | `object (SerializedSource)` | Источник клиента |
| `orders[][customer][source][source]` | `string` | Источник |
| `orders[][customer][source][medium]` | `string` | Канал |
| `orders[][customer][source][campaign]` | `string` | Кампания |
| `orders[][customer][source][keyword]` | `string` | Ключевое слово |
| `orders[][customer][source][content]` | `string` | Содержание кампании |
| `orders[][customer][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `orders[][customer][mgCustomers][][id]` | `integer` | ID клиента |
| `orders[][customer][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `orders[][customer][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `orders[][customer][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `orders[][customer][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `orders[][customer][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `orders[][customer][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `orders[][customer][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `orders[][customer][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `orders[][customer][photoUrl]` | `string` | URL фотографии |
| `orders[][contact]` | `object (Customer)` | Контактное лицо |
| `orders[][contact][type]` | `string` | Тип клиента |
| `orders[][contact][id]` | `integer` | ID клиента |
| `orders[][contact][externalId]` | `string` | Внешний ID клиента |
| `orders[][contact][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `orders[][contact][createdAt]` | `DateTime` | Создан |
| `orders[][contact][managerId]` | `integer` | Менеджер клиента |
| `orders[][contact][vip]` | `boolean` | Важный клиент |
| `orders[][contact][bad]` | `boolean` | Плохой клиент |
| `orders[][contact][site]` | `string` | Магазин, с которого пришел клиент |
| `orders[][contact][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `orders[][contact][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][contact][contragent][legalName]` | `string` | Полное наименование |
| `orders[][contact][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][contact][contragent][INN]` | `string` | ИНН |
| `orders[][contact][contragent][OKPO]` | `string` | ОКПО |
| `orders[][contact][contragent][KPP]` | `string` | КПП |
| `orders[][contact][contragent][OGRN]` | `string` | ОГРН |
| `orders[][contact][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][contact][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][contact][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][contact][contragent][BIK]` | `string` | БИК |
| `orders[][contact][contragent][bank]` | `string` | Банк |
| `orders[][contact][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][contact][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][contact][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][contact][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `orders[][contact][tags][][color]` | `string` |     |
| `orders[][contact][tags][][name]` | `string` |     |
| `orders[][contact][tags][][colorCode]` | `string` |     |
| `orders[][contact][tags][][attached]` | `boolean` |     |
| `orders[][contact][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `orders[][contact][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `orders[][contact][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][contact][personalDiscount]` | `double` | Персональная скидка |
| `orders[][contact][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `orders[][contact][discountCardNumber]` | `string` | Номер дисконтной карты |
| `orders[][contact][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][contact][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][contact][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][contact][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][contact][ordersCount]` | `integer` | Количество заказов |
| `orders[][contact][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][contact][address]` | `object (CustomerAddress)` | Адрес клиента |
| `orders[][contact][address][id]` | `integer` | ID адреса |
| `orders[][contact][address][index]` | `string` | Индекс |
| `orders[][contact][address][countryIso]` | `string` | ISO код страны |
| `orders[][contact][address][region]` | `string` | Регион |
| `orders[][contact][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][contact][address][city]` | `string` | Город |
| `orders[][contact][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][contact][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][contact][address][street]` | `string` | Улица |
| `orders[][contact][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][contact][address][streetType]` | `string` | Тип улицы |
| `orders[][contact][address][building]` | `string` | Дом |
| `orders[][contact][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][contact][address][floor]` | `integer` | Этаж |
| `orders[][contact][address][block]` | `integer` | Подъезд |
| `orders[][contact][address][house]` | `string` | Строение |
| `orders[][contact][address][housing]` | `string` | Корпус |
| `orders[][contact][address][metro]` | `string` | Метро |
| `orders[][contact][address][notes]` | `string` | Примечания к адресу |
| `orders[][contact][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][contact][address][externalId]` | `string` | Внешний ID |
| `orders[][contact][address][name]` | `string` | Наменование адреса |
| `orders[][contact][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `orders[][contact][segments][][id]` | `integer` | Внутренний ID сегмента |
| `orders[][contact][segments][][code]` | `string` | Символьный код |
| `orders[][contact][segments][][name]` | `string` | Название сегмента |
| `orders[][contact][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `orders[][contact][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `orders[][contact][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `orders[][contact][segments][][active]` | `boolean` | Активность сегмента |
| `orders[][contact][maturationTime]` | `integer` | Время «созревания», в секундах |
| `orders[][contact][firstName]` | `string` | Имя |
| `orders[][contact][lastName]` | `string` | Фамилия |
| `orders[][contact][patronymic]` | `string` | Отчество |
| `orders[][contact][sex]` | `string` | Пол |
| `orders[][contact][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `orders[][contact][email]` | `string` | E-mail |
| `orders[][contact][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `orders[][contact][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `orders[][contact][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `orders[][contact][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `orders[][contact][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `orders[][contact][customerSubscriptions][][subscription][name]` | `string` | Название |
| `orders[][contact][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `orders[][contact][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `orders[][contact][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `orders[][contact][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `orders[][contact][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `orders[][contact][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `orders[][contact][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `orders[][contact][phones][][number]` | `string` | Номер телефона |
| `orders[][contact][birthday]` | `DateTime` | День рождения |
| `orders[][contact][source]` | `object (SerializedSource)` | Источник клиента |
| `orders[][contact][source][source]` | `string` | Источник |
| `orders[][contact][source][medium]` | `string` | Канал |
| `orders[][contact][source][campaign]` | `string` | Кампания |
| `orders[][contact][source][keyword]` | `string` | Ключевое слово |
| `orders[][contact][source][content]` | `string` | Содержание кампании |
| `orders[][contact][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `orders[][contact][mgCustomers][][id]` | `integer` | ID клиента |
| `orders[][contact][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `orders[][contact][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `orders[][contact][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `orders[][contact][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `orders[][contact][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `orders[][contact][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `orders[][contact][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `orders[][contact][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `orders[][contact][photoUrl]` | `string` | URL фотографии |
| `orders[][company]` | `object (Company)` | Компания |
| `orders[][company][id]` | `integer` | ID компании |
| `orders[][company][externalId]` | `string` | Внешний ID компании |
| `orders[][company][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `orders[][company][customer][site]` | `string` | Символьный код магазина |
| `orders[][company][customer][id]` | `integer` | Внутренний ID клиента |
| `orders[][company][customer][externalId]` | `string` | Внешний ID клиента |
| `orders[][company][customer][type]` | `string` | Тип клиента |
| `orders[][company][active]` | `boolean` | Активность |
| `orders[][company][name]` | `string` | Наименование |
| `orders[][company][brand]` | `string` | Бренд |
| `orders[][company][site]` | `string` | Сайт компании |
| `orders[][company][createdAt]` | `DateTime` | Дата создания |
| `orders[][company][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `orders[][company][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][company][contragent][legalName]` | `string` | Полное наименование |
| `orders[][company][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][company][contragent][INN]` | `string` | ИНН |
| `orders[][company][contragent][OKPO]` | `string` | ОКПО |
| `orders[][company][contragent][KPP]` | `string` | КПП |
| `orders[][company][contragent][OGRN]` | `string` | ОГРН |
| `orders[][company][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][company][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][company][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][company][contragent][BIK]` | `string` | БИК |
| `orders[][company][contragent][bank]` | `string` | Банк |
| `orders[][company][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][company][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][company][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][company][address]` | `object (CustomerAddress)` | Адрес |
| `orders[][company][address][id]` | `integer` | ID адреса |
| `orders[][company][address][index]` | `string` | Индекс |
| `orders[][company][address][countryIso]` | `string` | ISO код страны |
| `orders[][company][address][region]` | `string` | Регион |
| `orders[][company][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][company][address][city]` | `string` | Город |
| `orders[][company][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][company][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][company][address][street]` | `string` | Улица |
| `orders[][company][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][company][address][streetType]` | `string` | Тип улицы |
| `orders[][company][address][building]` | `string` | Дом |
| `orders[][company][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][company][address][floor]` | `integer` | Этаж |
| `orders[][company][address][block]` | `integer` | Подъезд |
| `orders[][company][address][house]` | `string` | Строение |
| `orders[][company][address][housing]` | `string` | Корпус |
| `orders[][company][address][metro]` | `string` | Метро |
| `orders[][company][address][notes]` | `string` | Примечания к адресу |
| `orders[][company][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][company][address][externalId]` | `string` | Внешний ID |
| `orders[][company][address][name]` | `string` | Наменование адреса |
| `orders[][company][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `orders[][company][marginSumm]` | `float` | LTV (в базовой валюте) |
| `orders[][company][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `orders[][company][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `orders[][company][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `orders[][company][ordersCount]` | `integer` | Количество заказов |
| `orders[][company][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][contragent]` | `object (OrderContragent)` | Реквизиты |
| `orders[][contragent][contragentType]` | `string` | Тип контрагента |
| `orders[][contragent][legalName]` | `string` | Полное наименование |
| `orders[][contragent][legalAddress]` | `string` | Адрес регистрации |
| `orders[][contragent][INN]` | `string` | ИНН |
| `orders[][contragent][OKPO]` | `string` | ОКПО |
| `orders[][contragent][KPP]` | `string` | КПП |
| `orders[][contragent][OGRN]` | `string` | ОГРН |
| `orders[][contragent][OGRNIP]` | `string` | ОГРНИП |
| `orders[][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `orders[][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `orders[][contragent][BIK]` | `string` | БИК |
| `orders[][contragent][bank]` | `string` | Банк |
| `orders[][contragent][bankAddress]` | `string` | Адрес банка |
| `orders[][contragent][corrAccount]` | `string` | Корр. счёт |
| `orders[][contragent][bankAccount]` | `string` | Расчётный счёт |
| `orders[][delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `orders[][delivery][code]` | `string` | Код типа доставки |
| `orders[][delivery][integrationCode]` | `string` | Интеграционный код типа доставки |
| `orders[][delivery][data]` | `object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData)` | Данные службы доставки, подключенной через API |
| `orders[][delivery][data][externalId]` | `string` | Идентификатор в службе доставки |
| `orders[][delivery][data][trackNumber]` | `string` | Номер отправления (поле `deprecated` на запись) |
| `orders[][delivery][data][status]` | `string` | Код статуса доставки |
| `orders[][delivery][data][locked]` | `boolean` | Не синхронизировать со службой доставки |
| `orders[][delivery][data][pickuppointAddress]` | `string` | Адрес пункта самовывоза |
| `orders[][delivery][data][days]` | `string` | Ориентировочный срок доставки |
| `orders[][delivery][data][statusText]` | `string` | Наименование статуса доставки |
| `orders[][delivery][data][statusDate]` | `DateTime` | Дата статуса доставки |
| `orders[][delivery][data][tariff]` | `string` | Код тарифа |
| `orders[][delivery][data][tariffName]` | `string` | Наименование тарифа |
| `orders[][delivery][data][pickuppointId]` | `string` | Идентификатор пункта самовывоза |
| `orders[][delivery][data][pickuppointSchedule]` | `string` | Режим работы пункта самовывоза |
| `orders[][delivery][data][pickuppointPhone]` | `string` | Телефон пункта самовывоза |
| `orders[][delivery][data][payerType]` | `string` | Плательщик за доставку |
| `orders[][delivery][data][statusComment]` | `string` | Комментарий к статусу доставки |
| `orders[][delivery][data][cost]` | `float` | Стоимость доставки, полученная из службы доставки (в валюте объекта) |
| `orders[][delivery][data][minTerm]` | `integer` | Минимальный срок доставки |
| `orders[][delivery][data][maxTerm]` | `integer` | Максимальный срок доставки |
| `orders[][delivery][data][shipmentpointId]` | `string` | Идентификатор терминала отгрузки |
| `orders[][delivery][data][shipmentpointName]` | `string` | Наименование терминала отгрузки |
| `orders[][delivery][data][shipmentpointAddress]` | `string` | Адрес терминала отгрузки |
| `orders[][delivery][data][shipmentpointSchedule]` | `string` | Режим работы терминала отгрузки |
| `orders[][delivery][data][shipmentpointPhone]` | `string` | Телефон терминала отгрузки |
| `orders[][delivery][data][shipmentpointCoordinateLatitude]` | `string` | Координаты терминала отгрузки, широта |
| `orders[][delivery][data][shipmentpointCoordinateLongitude]` | `string` | Координаты терминала отгрузки, долгота |
| `orders[][delivery][data][pickuppointName]` | `string` | Наименование пункта самовывоза |
| `orders[][delivery][data][pickuppointCoordinateLatitude]` | `string` | Координаты ПВЗ, широта |
| `orders[][delivery][data][pickuppointCoordinateLongitude]` | `string` | Координаты ПВЗ, долгота |
| `orders[][delivery][data][extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `orders[][delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` | Код |
| `orders[][delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` | Значение |
| `orders[][delivery][data][itemDeclaredValues][][value]` | `double` | Объявленная стоимость товара |
| `orders[][delivery][data][packages][]` | `array of objects (Package)` | Упаковки |
| `orders[][delivery][data][packages][][packageId]` | `string` | Идентификатор упаковки |
| `orders[][delivery][data][packages][][weight]` | `double` | Вес г. |
| `orders[][delivery][data][packages][][length]` | `integer` | Длина мм. |
| `orders[][delivery][data][packages][][width]` | `integer` | Ширина мм. |
| `orders[][delivery][data][packages][][height]` | `integer` | Высота мм. |
| `orders[][delivery][data][packages][][items][]` | `array of objects (PackageItem)` | Содержимое упаковки |
| `orders[][delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` | Код |
| `orders[][delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` | Значение |
| `orders[][delivery][data][packages][][items][][quantity]` | `double` | Количество товара в упаковке |
| `orders[][delivery][service]` | `object (SerializedDeliveryService)` |     |
| `orders[][delivery][service][name]` | `string` | Название |
| `orders[][delivery][service][code]` | `string` | Символьный код |
| `orders[][delivery][service][active]` | `boolean` | Статус активности |
| `orders[][delivery][cost]` | `double` | Стоимость доставки |
| `orders[][delivery][netCost]` | `double` | Себестоимость доставки |
| `orders[][delivery][date]` | `DateTime` | Дата доставки |
| `orders[][delivery][time]` | `object (TimeInterval)` | Информация о временном диапазоне |
| `orders[][delivery][time][from]` | `DateTime` | Время "с" |
| `orders[][delivery][time][to]` | `DateTime` | Время "до" |
| `orders[][delivery][time][custom]` | `string` | Временной диапазон в свободной форме |
| `orders[][delivery][address]` | `object (OrderDeliveryAddress)` | Адрес доставки |
| `orders[][delivery][address][index]` | `string` | Индекс |
| `orders[][delivery][address][countryIso]` | `string` | ISO код страны |
| `orders[][delivery][address][region]` | `string` | Регион |
| `orders[][delivery][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `orders[][delivery][address][city]` | `string` | Город |
| `orders[][delivery][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `orders[][delivery][address][cityType]` | `string` | Тип населенного пункта |
| `orders[][delivery][address][street]` | `string` | Улица |
| `orders[][delivery][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `orders[][delivery][address][streetType]` | `string` | Тип улицы |
| `orders[][delivery][address][building]` | `string` | Дом |
| `orders[][delivery][address][flat]` | `string` | Номер квартиры/офиса |
| `orders[][delivery][address][floor]` | `integer` | Этаж |
| `orders[][delivery][address][block]` | `integer` | Подъезд |
| `orders[][delivery][address][house]` | `string` | Строение |
| `orders[][delivery][address][housing]` | `string` | Корпус |
| `orders[][delivery][address][metro]` | `string` | Метро |
| `orders[][delivery][address][notes]` | `string` | Примечания к адресу |
| `orders[][delivery][address][text]` | `string` | Адрес в текстовом виде |
| `orders[][delivery][vatRate]` | `string` | Ставка НДС |
| `orders[][site]` | `string` | Магазин |
| `orders[][status]` | `string` | Статус заказа |
| `orders[][statusComment]` | `string` | Комментарий к последнему изменению статуса |
| `orders[][source]` | `object (SerializedSource)` | Источник заказа |
| `orders[][source][source]` | `string` | Источник |
| `orders[][source][medium]` | `string` | Канал |
| `orders[][source][campaign]` | `string` | Кампания |
| `orders[][source][keyword]` | `string` | Ключевое слово |
| `orders[][source][content]` | `string` | Содержание кампании |
| `orders[][items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `orders[][items][][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `orders[][items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `orders[][items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `orders[][items][][markingCodes][]` | `array of strings` | Коды маркировки |
| `orders[][items][][id]` | `integer` | ID позиции в заказе |
| `orders[][items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `orders[][items][][externalIds][][code]` | `string` | Код |
| `orders[][items][][externalIds][][value]` | `string` | Значение |
| `orders[][items][][priceType]` | `object (PriceType)` | Тип цены |
| `orders[][items][][priceType][code]` | `string` | Код типа цены |
| `orders[][items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `orders[][items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `orders[][items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `orders[][items][][discounts][][amount]` | `float` | Сумма скидки |
| `orders[][items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `orders[][items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `orders[][items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `orders[][items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `orders[][items][][vatRate]` | `string` | Ставка НДС |
| `orders[][items][][createdAt]` | `DateTime` | Дата создания позиции в системе |
| `orders[][items][][quantity]` | `float` | Количество |
| `orders[][items][][status]` | `string` | Статус позиции в заказе |
| `orders[][items][][comment]` | `string` | Комментарий к позиции в заказе |
| `orders[][items][][offer]` | `object (Offer)` | Торговое предложение |
| `orders[][items][][offer][displayName]` | `string` | Название SKU |
| `orders[][items][][offer][id]` | `integer` | ID торгового предложения |
| `orders[][items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `orders[][items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `orders[][items][][offer][name]` | `string` | Название |
| `orders[][items][][offer][article]` | `string` | Артикул |
| `orders[][items][][offer][vatRate]` | `string` | Ставка НДС |
| `orders[][items][][offer][properties]` | `array` | Свойства SKU |
| `orders[][items][][offer][unit]` | `object (Unit)` | Единица измерения |
| `orders[][items][][offer][unit][code]` | `string` | Символьный код |
| `orders[][items][][offer][unit][name]` | `string` | Название |
| `orders[][items][][offer][unit][sym]` | `string` | Краткое обозначение |
| `orders[][items][][offer][barcode]` | `string` | Штрих-код |
| `orders[][items][][isCanceled]` | `boolean` | Данная позиция в заказе является отменной |
| `orders[][items][][properties]` | `array` | \[массив\] Дополнительные свойства позиции в заказе |
| `orders[][items][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `orders[][items][][ordering]` | `integer` | Порядок |
| `orders[][fullPaidAt]` | `DateTime` | Дата полной оплаты |
| `orders[][payments][]` | `array of objects (Payment)` | Платежи |
| `orders[][payments][][id]` | `integer` | Внутренний ID |
| `orders[][payments][][status]` | `string` | Статус оплаты |
| `orders[][payments][][type]` | `string` | Тип оплаты |
| `orders[][payments][][externalId]` | `string` | Внешний ID платежа |
| `orders[][payments][][amount]` | `double` | Сумма платежа (в валюте объекта) |
| `orders[][payments][][paidAt]` | `DateTime` | Дата оплаты |
| `orders[][payments][][comment]` | `string` | Комментарий |
| `orders[][fromApi]` | `boolean` | Заказ поступил через API |
| `orders[][weight]` | `double` | Вес |
| `orders[][length]` | `integer` | Длина |
| `orders[][width]` | `integer` | Ширина |
| `orders[][height]` | `integer` | Высота |
| `orders[][shipmentStore]` | `string` | Склад отгрузки |
| `orders[][shipmentDate]` | `DateTime` | Дата отгрузки |
| `orders[][shipped]` | `boolean` | Заказ отгружен |
| `orders[][links][]` | `array of objects (OrderLink)` | Связь заказов |
| `orders[][links][][order]` | `object (LinkedOrder)` | Связанный заказ |
| `orders[][links][][order][id]` | `integer` | ID связанного заказа |
| `orders[][links][][order][number]` | `string` | Номер связанного заказа |
| `orders[][links][][order][externalId]` | `string` | Внешний ID связанного заказа |
| `orders[][links][][createdAt]` | `DateTime` | Дата/время создания связи с заказом |
| `orders[][links][][comment]` | `string` | Комментарий |
| `orders[][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `orders[][clientId]` | `string` | Метка клиента Google Analytics |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Все заказы успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Не передан массив заказов, либо массив слишком большой</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть заказов не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/{externalId}

Получение информации о заказе

Для доступа к методу необходимо разрешение `order_read`.

Метод возвращает полную информацию по заказу. Можно обращаться к заказу как по внешнему ID заказа (`by=externalId`), так и по внутреннему ID (`by=id`).

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

Пустые поля без значений не возвращаются.

В полях `orderType`, `orderMethod`, `payments[][type]`, `payments[][status]`, `status`, `site`, `delivery[code]` возвращается символьный код элемента.

В полях `managerId`, `sourceId` возвращается внутренний ID сущности в системе.

В поле `customFields` возвращается массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Если адрес доставки указывался в строковом виде, то он будет возвращен в `delivery[address][text]`. Если адрес указывался в детальном виде, то будут возвращены все заполненные поля доставки, а в `delivery[address][text]` будет находиться автоматически сформированное текстовое представление адреса.

Поле `privilegeType` может содержать одно из следующих значений:

- `personal_discount` - персональная скидка клиента;
- `loyalty_level` - расчет скидки или начисление бонусов исходя из настроек уровня ПЛ клиента;
- `loyalty_event` - расчет скидки по событию ПЛ;
- `none` - не применять скидки ПЛ к заказу;

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalId` | `string` |     | ID заказа |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID заказа. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `order` | `object (Order)` | Заказ |
| `order[slug]` | `custom handler result for (int)` | `deprecated` Символьный код |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[summ]` | `double` | Сумма по товарам/услугам (в валюте объекта) |
| `order[currency]` | `string` | Валюта |
| `order[id]` | `integer` | ID заказа |
| `order[number]` | `string` | Номер заказа |
| `order[externalId]` | `string` | Внешний ID заказа |
| `order[orderType]` | `string` | Тип заказа |
| `order[orderMethod]` | `string` | Способ оформления |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[countryIso]` | `string` | ISO код страны |
| `order[createdAt]` | `DateTime` | Дата оформления заказа |
| `order[statusUpdatedAt]` | `DateTime` | Дата последнего изменения статуса |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[prepaySum]` | `double` | Оплаченная сумма (в валюте объекта) |
| `order[purchaseSumm]` | `double` | Общая стоимость закупки (в базовой валюте) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[mark]` | `integer` | Оценка заказа |
| `order[markDatetime]` | `DateTime` | Дата и время получение оценки от покупателя |
| `order[lastName]` | `string` | Фамилия |
| `order[firstName]` | `string` | Имя |
| `order[patronymic]` | `string` | Отчество |
| `order[phone]` | `string` | Телефон |
| `order[additionalPhone]` | `string` | Дополнительный телефон |
| `order[email]` | `string` | E-mail |
| `order[call]` | `boolean` | Требуется позвонить |
| `order[expired]` | `boolean` | Просрочен |
| `order[customerComment]` | `string` | Комментарий клиента |
| `order[managerComment]` | `string` | Комментарий оператора |
| `order[managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `order[customer]` | `object (Customer)object (CustomerCorporate)` | Клиент |
| `order[customer][type]` | `string` | Тип клиента |
| `order[customer][id]` | `integer` | ID клиента |
| `order[customer][externalId]` | `string` | Внешний ID клиента |
| `order[customer][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[customer][createdAt]` | `DateTime` | Создан |
| `order[customer][managerId]` | `integer` | Менеджер клиента |
| `order[customer][vip]` | `boolean` | Важный клиент |
| `order[customer][bad]` | `boolean` | Плохой клиент |
| `order[customer][site]` | `string` | Магазин, с которого пришел клиент |
| `order[customer][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[customer][contragent][contragentType]` | `string` | Тип контрагента |
| `order[customer][contragent][legalName]` | `string` | Полное наименование |
| `order[customer][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[customer][contragent][INN]` | `string` | ИНН |
| `order[customer][contragent][OKPO]` | `string` | ОКПО |
| `order[customer][contragent][KPP]` | `string` | КПП |
| `order[customer][contragent][OGRN]` | `string` | ОГРН |
| `order[customer][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[customer][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[customer][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[customer][contragent][BIK]` | `string` | БИК |
| `order[customer][contragent][bank]` | `string` | Банк |
| `order[customer][contragent][bankAddress]` | `string` | Адрес банка |
| `order[customer][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[customer][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[customer][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[customer][tags][][color]` | `string` |     |
| `order[customer][tags][][name]` | `string` |     |
| `order[customer][tags][][colorCode]` | `string` |     |
| `order[customer][tags][][attached]` | `boolean` |     |
| `order[customer][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[customer][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[customer][personalDiscount]` | `double` | Персональная скидка |
| `order[customer][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[customer][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[customer][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[customer][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[customer][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[customer][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[customer][ordersCount]` | `integer` | Количество заказов |
| `order[customer][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[customer][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[customer][address][id]` | `integer` | ID адреса |
| `order[customer][address][index]` | `string` | Индекс |
| `order[customer][address][countryIso]` | `string` | ISO код страны |
| `order[customer][address][region]` | `string` | Регион |
| `order[customer][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[customer][address][city]` | `string` | Город |
| `order[customer][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[customer][address][cityType]` | `string` | Тип населенного пункта |
| `order[customer][address][street]` | `string` | Улица |
| `order[customer][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[customer][address][streetType]` | `string` | Тип улицы |
| `order[customer][address][building]` | `string` | Дом |
| `order[customer][address][flat]` | `string` | Номер квартиры/офиса |
| `order[customer][address][floor]` | `integer` | Этаж |
| `order[customer][address][block]` | `integer` | Подъезд |
| `order[customer][address][house]` | `string` | Строение |
| `order[customer][address][housing]` | `string` | Корпус |
| `order[customer][address][metro]` | `string` | Метро |
| `order[customer][address][notes]` | `string` | Примечания к адресу |
| `order[customer][address][text]` | `string` | Адрес в текстовом виде |
| `order[customer][address][externalId]` | `string` | Внешний ID |
| `order[customer][address][name]` | `string` | Наменование адреса |
| `order[customer][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[customer][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[customer][segments][][code]` | `string` | Символьный код |
| `order[customer][segments][][name]` | `string` | Название сегмента |
| `order[customer][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[customer][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[customer][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[customer][segments][][active]` | `boolean` | Активность сегмента |
| `order[customer][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[customer][firstName]` | `string` | Имя |
| `order[customer][lastName]` | `string` | Фамилия |
| `order[customer][patronymic]` | `string` | Отчество |
| `order[customer][sex]` | `string` | Пол |
| `order[customer][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[customer][email]` | `string` | E-mail |
| `order[customer][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[customer][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[customer][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[customer][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[customer][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[customer][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[customer][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[customer][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[customer][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[customer][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[customer][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[customer][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[customer][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[customer][phones][][number]` | `string` | Номер телефона |
| `order[customer][birthday]` | `DateTime` | День рождения |
| `order[customer][source]` | `object (SerializedSource)` | Источник клиента |
| `order[customer][source][source]` | `string` | Источник |
| `order[customer][source][medium]` | `string` | Канал |
| `order[customer][source][campaign]` | `string` | Кампания |
| `order[customer][source][keyword]` | `string` | Ключевое слово |
| `order[customer][source][content]` | `string` | Содержание кампании |
| `order[customer][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[customer][mgCustomers][][id]` | `integer` | ID клиента |
| `order[customer][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[customer][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[customer][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[customer][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[customer][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[customer][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[customer][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[customer][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[customer][photoUrl]` | `string` | URL фотографии |
| `order[contact]` | `object (Customer)` | Контактное лицо |
| `order[contact][type]` | `string` | Тип клиента |
| `order[contact][id]` | `integer` | ID клиента |
| `order[contact][externalId]` | `string` | Внешний ID клиента |
| `order[contact][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[contact][createdAt]` | `DateTime` | Создан |
| `order[contact][managerId]` | `integer` | Менеджер клиента |
| `order[contact][vip]` | `boolean` | Важный клиент |
| `order[contact][bad]` | `boolean` | Плохой клиент |
| `order[contact][site]` | `string` | Магазин, с которого пришел клиент |
| `order[contact][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[contact][contragent][contragentType]` | `string` | Тип контрагента |
| `order[contact][contragent][legalName]` | `string` | Полное наименование |
| `order[contact][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contact][contragent][INN]` | `string` | ИНН |
| `order[contact][contragent][OKPO]` | `string` | ОКПО |
| `order[contact][contragent][KPP]` | `string` | КПП |
| `order[contact][contragent][OGRN]` | `string` | ОГРН |
| `order[contact][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contact][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contact][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contact][contragent][BIK]` | `string` | БИК |
| `order[contact][contragent][bank]` | `string` | Банк |
| `order[contact][contragent][bankAddress]` | `string` | Адрес банка |
| `order[contact][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contact][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[contact][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[contact][tags][][color]` | `string` |     |
| `order[contact][tags][][name]` | `string` |     |
| `order[contact][tags][][colorCode]` | `string` |     |
| `order[contact][tags][][attached]` | `boolean` |     |
| `order[contact][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[contact][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[contact][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contact][personalDiscount]` | `double` | Персональная скидка |
| `order[contact][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[contact][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[contact][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[contact][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[contact][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[contact][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[contact][ordersCount]` | `integer` | Количество заказов |
| `order[contact][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[contact][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[contact][address][id]` | `integer` | ID адреса |
| `order[contact][address][index]` | `string` | Индекс |
| `order[contact][address][countryIso]` | `string` | ISO код страны |
| `order[contact][address][region]` | `string` | Регион |
| `order[contact][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[contact][address][city]` | `string` | Город |
| `order[contact][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[contact][address][cityType]` | `string` | Тип населенного пункта |
| `order[contact][address][street]` | `string` | Улица |
| `order[contact][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[contact][address][streetType]` | `string` | Тип улицы |
| `order[contact][address][building]` | `string` | Дом |
| `order[contact][address][flat]` | `string` | Номер квартиры/офиса |
| `order[contact][address][floor]` | `integer` | Этаж |
| `order[contact][address][block]` | `integer` | Подъезд |
| `order[contact][address][house]` | `string` | Строение |
| `order[contact][address][housing]` | `string` | Корпус |
| `order[contact][address][metro]` | `string` | Метро |
| `order[contact][address][notes]` | `string` | Примечания к адресу |
| `order[contact][address][text]` | `string` | Адрес в текстовом виде |
| `order[contact][address][externalId]` | `string` | Внешний ID |
| `order[contact][address][name]` | `string` | Наменование адреса |
| `order[contact][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[contact][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[contact][segments][][code]` | `string` | Символьный код |
| `order[contact][segments][][name]` | `string` | Название сегмента |
| `order[contact][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[contact][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[contact][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[contact][segments][][active]` | `boolean` | Активность сегмента |
| `order[contact][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[contact][firstName]` | `string` | Имя |
| `order[contact][lastName]` | `string` | Фамилия |
| `order[contact][patronymic]` | `string` | Отчество |
| `order[contact][sex]` | `string` | Пол |
| `order[contact][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[contact][email]` | `string` | E-mail |
| `order[contact][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[contact][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[contact][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[contact][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[contact][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[contact][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[contact][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[contact][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[contact][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[contact][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[contact][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[contact][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[contact][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[contact][phones][][number]` | `string` | Номер телефона |
| `order[contact][birthday]` | `DateTime` | День рождения |
| `order[contact][source]` | `object (SerializedSource)` | Источник клиента |
| `order[contact][source][source]` | `string` | Источник |
| `order[contact][source][medium]` | `string` | Канал |
| `order[contact][source][campaign]` | `string` | Кампания |
| `order[contact][source][keyword]` | `string` | Ключевое слово |
| `order[contact][source][content]` | `string` | Содержание кампании |
| `order[contact][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[contact][mgCustomers][][id]` | `integer` | ID клиента |
| `order[contact][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[contact][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[contact][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[contact][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[contact][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[contact][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[contact][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[contact][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[contact][photoUrl]` | `string` | URL фотографии |
| `order[company]` | `object (Company)` | Компания |
| `order[company][id]` | `integer` | ID компании |
| `order[company][externalId]` | `string` | Внешний ID компании |
| `order[company][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `order[company][customer][site]` | `string` | Символьный код магазина |
| `order[company][customer][id]` | `integer` | Внутренний ID клиента |
| `order[company][customer][externalId]` | `string` | Внешний ID клиента |
| `order[company][customer][type]` | `string` | Тип клиента |
| `order[company][active]` | `boolean` | Активность |
| `order[company][name]` | `string` | Наименование |
| `order[company][brand]` | `string` | Бренд |
| `order[company][site]` | `string` | Сайт компании |
| `order[company][createdAt]` | `DateTime` | Дата создания |
| `order[company][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `order[company][contragent][contragentType]` | `string` | Тип контрагента |
| `order[company][contragent][legalName]` | `string` | Полное наименование |
| `order[company][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[company][contragent][INN]` | `string` | ИНН |
| `order[company][contragent][OKPO]` | `string` | ОКПО |
| `order[company][contragent][KPP]` | `string` | КПП |
| `order[company][contragent][OGRN]` | `string` | ОГРН |
| `order[company][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[company][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[company][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[company][contragent][BIK]` | `string` | БИК |
| `order[company][contragent][bank]` | `string` | Банк |
| `order[company][contragent][bankAddress]` | `string` | Адрес банка |
| `order[company][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[company][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[company][address]` | `object (CustomerAddress)` | Адрес |
| `order[company][address][id]` | `integer` | ID адреса |
| `order[company][address][index]` | `string` | Индекс |
| `order[company][address][countryIso]` | `string` | ISO код страны |
| `order[company][address][region]` | `string` | Регион |
| `order[company][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[company][address][city]` | `string` | Город |
| `order[company][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[company][address][cityType]` | `string` | Тип населенного пункта |
| `order[company][address][street]` | `string` | Улица |
| `order[company][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[company][address][streetType]` | `string` | Тип улицы |
| `order[company][address][building]` | `string` | Дом |
| `order[company][address][flat]` | `string` | Номер квартиры/офиса |
| `order[company][address][floor]` | `integer` | Этаж |
| `order[company][address][block]` | `integer` | Подъезд |
| `order[company][address][house]` | `string` | Строение |
| `order[company][address][housing]` | `string` | Корпус |
| `order[company][address][metro]` | `string` | Метро |
| `order[company][address][notes]` | `string` | Примечания к адресу |
| `order[company][address][text]` | `string` | Адрес в текстовом виде |
| `order[company][address][externalId]` | `string` | Внешний ID |
| `order[company][address][name]` | `string` | Наменование адреса |
| `order[company][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[company][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[company][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[company][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[company][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[company][ordersCount]` | `integer` | Количество заказов |
| `order[company][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contragent]` | `object (OrderContragent)` | Реквизиты |
| `order[contragent][contragentType]` | `string` | Тип контрагента |
| `order[contragent][legalName]` | `string` | Полное наименование |
| `order[contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contragent][INN]` | `string` | ИНН |
| `order[contragent][OKPO]` | `string` | ОКПО |
| `order[contragent][KPP]` | `string` | КПП |
| `order[contragent][OGRN]` | `string` | ОГРН |
| `order[contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contragent][BIK]` | `string` | БИК |
| `order[contragent][bank]` | `string` | Банк |
| `order[contragent][bankAddress]` | `string` | Адрес банка |
| `order[contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][code]` | `string` | Код типа доставки |
| `order[delivery][integrationCode]` | `string` | Интеграционный код типа доставки |
| `order[delivery][data]` | `object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData)` | Данные службы доставки, подключенной через API |
| `order[delivery][data][externalId]` | `string` | Идентификатор в службе доставки |
| `order[delivery][data][trackNumber]` | `string` | Номер отправления (поле `deprecated` на запись) |
| `order[delivery][data][status]` | `string` | Код статуса доставки |
| `order[delivery][data][locked]` | `boolean` | Не синхронизировать со службой доставки |
| `order[delivery][data][pickuppointAddress]` | `string` | Адрес пункта самовывоза |
| `order[delivery][data][days]` | `string` | Ориентировочный срок доставки |
| `order[delivery][data][statusText]` | `string` | Наименование статуса доставки |
| `order[delivery][data][statusDate]` | `DateTime` | Дата статуса доставки |
| `order[delivery][data][tariff]` | `string` | Код тарифа |
| `order[delivery][data][tariffName]` | `string` | Наименование тарифа |
| `order[delivery][data][pickuppointId]` | `string` | Идентификатор пункта самовывоза |
| `order[delivery][data][pickuppointSchedule]` | `string` | Режим работы пункта самовывоза |
| `order[delivery][data][pickuppointPhone]` | `string` | Телефон пункта самовывоза |
| `order[delivery][data][payerType]` | `string` | Плательщик за доставку |
| `order[delivery][data][statusComment]` | `string` | Комментарий к статусу доставки |
| `order[delivery][data][cost]` | `float` | Стоимость доставки, полученная из службы доставки (в валюте объекта) |
| `order[delivery][data][minTerm]` | `integer` | Минимальный срок доставки |
| `order[delivery][data][maxTerm]` | `integer` | Максимальный срок доставки |
| `order[delivery][data][shipmentpointId]` | `string` | Идентификатор терминала отгрузки |
| `order[delivery][data][shipmentpointName]` | `string` | Наименование терминала отгрузки |
| `order[delivery][data][shipmentpointAddress]` | `string` | Адрес терминала отгрузки |
| `order[delivery][data][shipmentpointSchedule]` | `string` | Режим работы терминала отгрузки |
| `order[delivery][data][shipmentpointPhone]` | `string` | Телефон терминала отгрузки |
| `order[delivery][data][shipmentpointCoordinateLatitude]` | `string` | Координаты терминала отгрузки, широта |
| `order[delivery][data][shipmentpointCoordinateLongitude]` | `string` | Координаты терминала отгрузки, долгота |
| `order[delivery][data][pickuppointName]` | `string` | Наименование пункта самовывоза |
| `order[delivery][data][pickuppointCoordinateLatitude]` | `string` | Координаты ПВЗ, широта |
| `order[delivery][data][pickuppointCoordinateLongitude]` | `string` | Координаты ПВЗ, долгота |
| `order[delivery][data][extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `order[delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |
| `order[delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][itemDeclaredValues][][value]` | `double` | Объявленная стоимость товара |
| `order[delivery][data][packages][]` | `array of objects (Package)` | Упаковки |
| `order[delivery][data][packages][][packageId]` | `string` | Идентификатор упаковки |
| `order[delivery][data][packages][][weight]` | `double` | Вес г. |
| `order[delivery][data][packages][][length]` | `integer` | Длина мм. |
| `order[delivery][data][packages][][width]` | `integer` | Ширина мм. |
| `order[delivery][data][packages][][height]` | `integer` | Высота мм. |
| `order[delivery][data][packages][][items][]` | `array of objects (PackageItem)` | Содержимое упаковки |
| `order[delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][packages][][items][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][packages][][items][][quantity]` | `double` | Количество товара в упаковке |
| `order[delivery][service]` | `object (SerializedDeliveryService)` |     |
| `order[delivery][service][name]` | `string` | Название |
| `order[delivery][service][code]` | `string` | Символьный код |
| `order[delivery][service][active]` | `boolean` | Статус активности |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[delivery][netCost]` | `double` | Себестоимость доставки |
| `order[delivery][date]` | `DateTime` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | Время "до" |
| `order[delivery][time][custom]` | `string` | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` | Адрес доставки |
| `order[delivery][address][index]` | `string` | Индекс |
| `order[delivery][address][countryIso]` | `string` | ISO код страны |
| `order[delivery][address][region]` | `string` | Регион |
| `order[delivery][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` | Город |
| `order[delivery][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` | Тип населенного пункта |
| `order[delivery][address][street]` | `string` | Улица |
| `order[delivery][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` | Тип улицы |
| `order[delivery][address][building]` | `string` | Дом |
| `order[delivery][address][flat]` | `string` | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` | Этаж |
| `order[delivery][address][block]` | `integer` | Подъезд |
| `order[delivery][address][house]` | `string` | Строение |
| `order[delivery][address][housing]` | `string` | Корпус |
| `order[delivery][address][metro]` | `string` | Метро |
| `order[delivery][address][notes]` | `string` | Примечания к адресу |
| `order[delivery][address][text]` | `string` | Адрес в текстовом виде |
| `order[delivery][vatRate]` | `string` | Ставка НДС |
| `order[site]` | `string` | Магазин |
| `order[status]` | `string` | Статус заказа |
| `order[statusComment]` | `string` | Комментарий к последнему изменению статуса |
| `order[source]` | `object (SerializedSource)` | Источник заказа |
| `order[source][source]` | `string` | Источник |
| `order[source][medium]` | `string` | Канал |
| `order[source][campaign]` | `string` | Кампания |
| `order[source][keyword]` | `string` | Ключевое слово |
| `order[source][content]` | `string` | Содержание кампании |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][markingCodes][]` | `array of strings` | Коды маркировки |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][createdAt]` | `DateTime` | Дата создания позиции в системе |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][status]` | `string` | Статус позиции в заказе |
| `order[items][][comment]` | `string` | Комментарий к позиции в заказе |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][displayName]` | `string` | Название SKU |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `order[items][][offer][name]` | `string` | Название |
| `order[items][][offer][article]` | `string` | Артикул |
| `order[items][][offer][vatRate]` | `string` | Ставка НДС |
| `order[items][][offer][properties][]` | `array` | Свойства SKU |
| `order[items][][offer][quantity]` | `float` | Доступное количество |
| `order[items][][offer][unit]` | `object (Unit)` | Единица измерения |
| `order[items][][offer][unit][code]` | `string` | Символьный код |
| `order[items][][offer][unit][name]` | `string` | Название |
| `order[items][][offer][unit][sym]` | `string` | Краткое обозначение |
| `order[items][][offer][barcode]` | `string` | Штрих-код |
| `order[items][][isCanceled]` | `boolean` | Данная позиция в заказе является отменной |
| `order[items][][properties][]` | `array` | \[массив\] Дополнительные свойства позиции в заказе |
| `order[items][][properties][][code]` | `string` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `order[items][][properties][][name]` | `string` | Имя свойства |
| `order[items][][properties][][value]` | `string` | Значение свойства |
| `order[items][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `order[items][][ordering]` | `integer` | Порядок |
| `order[fullPaidAt]` | `DateTime` | Дата полной оплаты |
| `order[payments][]` | `array of objects (Payment)` | Платежи |
| `order[payments][][id]` | `integer` | Внутренний ID |
| `order[payments][][status]` | `string` | Статус оплаты |
| `order[payments][][type]` | `string` | Тип оплаты |
| `order[payments][][externalId]` | `string` | Внешний ID платежа |
| `order[payments][][amount]` | `double` | Сумма платежа (в валюте объекта) |
| `order[payments][][paidAt]` | `DateTime` | Дата оплаты |
| `order[payments][][comment]` | `string` | Комментарий |
| `order[fromApi]` | `boolean` | Заказ поступил через API |
| `order[weight]` | `double` | Вес |
| `order[length]` | `integer` | Длина |
| `order[width]` | `integer` | Ширина |
| `order[height]` | `integer` | Высота |
| `order[shipmentStore]` | `string` | Склад отгрузки |
| `order[shipmentDate]` | `DateTime` | Дата отгрузки |
| `order[shipped]` | `boolean` | Заказ отгружен |
| `order[links][]` | `array of objects (OrderLink)` | Связь заказов |
| `order[links][][order]` | `object (LinkedOrder)` | Связанный заказ |
| `order[links][][order][id]` | `integer` | ID связанного заказа |
| `order[links][][order][number]` | `string` | Номер связанного заказа |
| `order[links][][order][externalId]` | `string` | Внешний ID связанного заказа |
| `order[links][][createdAt]` | `DateTime` | Дата/время создания связи с заказом |
| `order[links][][comment]` | `string` | Комментарий |
| `order[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[clientId]` | `string` | Метка клиента Google Analytics |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заказ не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/{externalId}/delivery/cancel

Отмена интеграционной доставки

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет отменить интеграционную доставку у конкретного заказа.

Если в заказе нет интеграционной доставки, либо она есть, но уже отменена, либо по какой-то причине не может быть отменена - вернётся ошибка.

Примечание к параметру `force`: если значение `true`, то при возникновении ошибки, при попытке отмены доставки в сервисе доставки, помечаем доставку как удалённую в нашей системе. Если `false`, то статус доставки не перейдет в статус отмены, а также вернётся сообщение об ошибке почему не удалось отменить доставку в сервисе доставки.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Поле для идентификации заказа, в котором нужно отменить доставку. Возможные значения `id`, `externalId`. По умолчанию `externalId`. |
| `force` | `boolean` |     | Если значение `true` - доставка будет помечена как отменённая, даже если не удалось её отменить в стороннем сервисе. Если `false`, то в случае ошибки от сервера службы доставки - отмена прерывается. |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### POST /api/v5/orders/{externalId}/edit

Редактирование заказа

Для доступа к методу необходимо разрешение `order_write`.

Метод позволяет вносить изменения в заказ. Можно обращаться к заказу как по внешнему ID заказа (`by=externalId`), так и по внутреннему ID (`by=id`).

В случае, если производится попытка отредактировать удаленный заказ, система возвращает в ответе `state=removed`.

Поле `contragent[contragentType]` может принимать 3 значения: _individual_ - физическое лицо, _legal-entity_ - юридическое лицо, _enterpreneur_ - индивидуальный предприниматель. Для различных типов юр. лиц доступны различные наборы полей. Для типа _individual_ недоступны все поля, для типа _legal-entity_ недоступны поля `contragent[OGRNIP]`, `contragent[certificateNumber]`, `contragent[certificateDate]`, для типа _enterpreneur_ недоступны поля `contragent[OGRN]`, `contragent[KPP]`.

В полях `order[orderType]`, `order[orderMethod]`, `order[status]`, `order[shipmentStore]`, `order[delivery][code]`, `order[items][][status]` указывается символьный код элемента.

В полях `order[managerId]`, `order[sourceId]` указывается внутренний ID сущности в системе.

Нельзя изменять комментарий `order[statusComment]` без изменения статуса заказа `order[status]`.

Товары заказа указываются в поле `order[items][]`. Не переданные в запросе на редактирование товары удаляются из заказа. Если товар присутствует в каталоге, то необходимо установить значение одного из следующих полей:

- `order[items][][offer][id]` – внутренний ID торгового предложения;
- `order[items][][offer][externalId]` – внешний ID товара или торгового предложения (SKU);
- `order[items][][offer][xmlId]` – ID торгового предложения в складской системе.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке.

В случае, если ни один из идентификаторов товара не передан либо товар не найден, то товар будет автоматически создан на основе данных полей `order[items][][initialPrice]`, `order[items][][purchasePrice]`, `order[items][][productName]`, при этом данная позиция товара в заказе не привязывается к товару в каталоге.

Адрес доставки `order[delivery][address]` можно указывать либо в строковом виде в поле `order[delivery][address][text]`, либо в подробном виде, заполняя все поля кроме `order[delivery][address][text]`.

В поле `order[customFields]` можно передавать массив значений пользовательских полей. Для полей типа «Справочник» указывается символьный код значения в справочнике. Для полей типа «Дата» указывается дата в формате `Y-m-d`. Для других типов полей указывается непосредственно значение.

Для работы с типами цен необходимо, чтобы в справочнике было активно более одного типа цен. Для передачи типа цены для товарной позиции в заказе необходимо передать код нужного типа цен в поле `order[items][][priceType][code]`. Рекомендуется вместе с типом цены передавать актуальное значение цены товара через `order[items][][initialPrice]`. Если передать тип цены `order[items][][priceType][code]` без значения цены `order[items][][initialPrice]`, то в качестве цены товарной позиции возьмется текущее значения данного типа цен для данного товара. Для новой товарной позиции рекомендуется всегда передавать цену `order[items][][initialPrice]` явно, на случай если актуальная цена еще не была загружена в систему. Если для товара не передать тип цены `order[items][][priceType][code]`, то в карточке заказа для товарной позиции в типе цены будет указанно `Без типа`. В случае, если в системе используется только базовый тип цен, то параметр `order[items][][priceType][code]` следует опустить.

Порядок позиций заказа `order[items][]` сохраняется в ответе.

Параметры `order[items][][externalId]` и `order[items][][externalIds]` являются не обязательными.

Одновременно можно указывать или значение внешнего идентификатора `order[items][][externalId]` или массив внешних идентификаторов `order[items][][externalIds]`.

Значение внешнего идентификатора `order[items][][externalId]` будет записано в массив `order[items][][externalIds]` с кодом `default`.

Значения внешних идентификаторов `order[items][][externalIds][][value]` должны быть уникальны по коду `order[items][][externalIds][][code]` в пределах одного заказа.

Поле `privilegeType` может содержать одно из следующих значений:

- `personal_discount` - персональная скидка клиента;
- `loyalty_level` - расчет скидки или начисление бонусов исходя из настроек уровня ПЛ клиента;
- `loyalty_event` - расчет скидки по событию ПЛ;
- `none` - не применять скидки ПЛ к заказу;

Для применения Программы лояльности к заказу необходимо чтобы соблюдались условия:

- магазин заказа совпадал с магазином нужной ПЛ;
- у клиента в заказе было создано Участие в ПЛ;
- указано поле `privilegeType`;

Если в `privilegeType` указано значение `loyalty_event` и событие дает скидку, то необходимо указать ID в поле `loyaltyEventDiscountId`

Если в заказе есть позиции с одинаковыми торговыми предложениями, то для их редактирования требуется передавать идентификаторы (`order[items][][id]` или `order[items][][externalId]` или `order[items][][externalIds]`).

При редактировании позиции через идентификаторы (`order[items][][id]` или `order[items][][externalId]` или `order[items][][externalIds]`) нельзя изменять торговое предложение `order[items][][offer]`.

При редактировании заказа обновление позиций происходит в следующей последовательности:

- Если в запросе API указан идентификатор позиции `order[items][][id]`, но он не существует в системе, то будет выдано соответствующее сообщение об ошибке.
- Если в запросе API указан идентификатор позиции `order[items][][id]`, который существует в системе, но относится к другому заказу, то будет выдано соответствующее сообщение об ошибке.
- Если в запросе API указан идентификатор позиции `order[items][][id]`, который существует в системе и относится к редактируемому заказу, то будет произведено обновление позиции.
- Если в запросе API НЕ указан идентификатор позиции `order[items][][id]`, но указан внешний идентификатор позиции `order[items][][externalId]`, который существует в системе и относится к редактируемому заказу, то будет произведено обновление позиции.
- Если в запросе API НЕ указан идентификатор позиции `order[items][][id]`, но указан внешний идентификатор позиции `order[items][][externalId]`, который НЕ существует в системе, то будет добавлена новая позиция.
- Если в запросе API НЕ указаны идентификаторы позиции (`order[items][][id]` и `order[items][][externalId]`), но или в заказе или в запросе API есть позиции с одинаковыми торговыми предложениями, то будет выдано соответствующее сообщение об ошибке.
- Если ни в заказе ни в запросе API нет позиций с одинаковыми торговыми предложениями, то для обновления позиции будет произведен поиск по идентификатору торгового предложения `order[items][][offer][id]`, по внешнему идентификатору торгового предложения `order[items][][offer][externalId]`, по идентификатору торгового предложения в складской системе `order[items][][offer][xmlId]`, иначе будет добавлена новая позиция.

Обратите внимание, что редактировать заказ, у которого есть позиции с одинаковыми торговыми предложениями, будет возможно только при активной настройке _"Возможность добавлять в заказ одинаковые торговые предложения как разные позиции"_.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID заказа. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина |
| `order` | `object (SerializedOrder)` |     |     |
| `order[number]` | `string` |     | Номер заказа |
| `order[externalId]` | `string` |     | Внешний ID заказа |
| `order[privilegeType]` | `string` |     | Тип привилегии |
| `order[countryIso]` | `string` |     | ISO код страны |
| `order[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата оформления заказа |
| `order[discountManualAmount]` | `double` |     | Денежная скидка на весь заказ (в валюте объекта) |
| `order[discountManualPercent]` | `double` |     | Процентная скидка на весь заказ |
| `order[mark]` | `integer` |     | Оценка заказа |
| `order[markDatetime]` | `DateTime` | `Y-m-d H:i:s` | Дата и время получение оценки от покупателя |
| `order[lastName]` | `string` |     | Фамилия |
| `order[firstName]` | `string` |     | Имя |
| `order[patronymic]` | `string` |     | Отчество |
| `order[phone]` | `string` |     | Телефон |
| `order[additionalPhone]` | `string` |     | Дополнительный телефон |
| `order[email]` | `string` |     | E-mail |
| `order[call]` | `boolean` |     | Требуется позвонить |
| `order[expired]` | `boolean` |     | Просрочен |
| `order[customerComment]` | `string` |     | Комментарий клиента |
| `order[managerComment]` | `string` |     | Комментарий оператора |
| `order[contragent]` | `object (OrderContragent)` |     | Реквизиты |
| `order[contragent][contragentType]` | `string` |     | Тип контрагента |
| `order[contragent][legalName]` | `string` |     | Полное наименование |
| `order[contragent][legalAddress]` | `string` |     | Адрес регистрации |
| `order[contragent][INN]` | `string` |     | ИНН |
| `order[contragent][OKPO]` | `string` |     | ОКПО |
| `order[contragent][KPP]` | `string` |     | КПП |
| `order[contragent][OGRN]` | `string` |     | ОГРН |
| `order[contragent][OGRNIP]` | `string` |     | ОГРНИП |
| `order[contragent][certificateNumber]` | `string` |     | Номер свидетельства |
| `order[contragent][certificateDate]` | `DateTime` | `Y-m-d` | Дата свидетельства |
| `order[contragent][BIK]` | `string` |     | БИК |
| `order[contragent][bank]` | `string` |     | Банк |
| `order[contragent][bankAddress]` | `string` |     | Адрес банка |
| `order[contragent][corrAccount]` | `string` |     | Корр. счёт |
| `order[contragent][bankAccount]` | `string` |     | Расчётный счёт |
| `order[statusComment]` | `string` |     | Комментарий к последнему изменению статуса |
| `order[weight]` | `double` |     | Вес |
| `order[length]` | `integer` |     | Длина |
| `order[width]` | `integer` |     | Ширина |
| `order[height]` | `integer` |     | Высота |
| `order[shipmentDate]` | `DateTime` | `Y-m-d` | Дата отгрузки |
| `order[shipped]` | `boolean` |     | Заказ отгружен |
| `order[dialogId]` | `object (MGDialog)` |     | Идентификатор диалога Чатов |
| `order[customFields]` | `array` |     | Ассоциативный массив пользовательских полей |
| `order[orderType]` | `string` |     | Тип заказа |
| `order[orderMethod]` | `string` |     | Способ оформления |
| `order[customer]` | `object (SerializedRelationCustomer)` |     | Клиент |
| `order[customer][id]` | `integer` |     | Внутренний ID клиента |
| `order[customer][externalId]` | `string` |     | Внешний ID клиента |
| `order[customer][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `order[customer][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `order[customer][type]` | `string` |     | Тип клиента (передаётся когда нужно создать нового клиента) |
| `order[customer][nickName]` | `string` |     | Наименование корпоративного клиента (передаётся когда нужно создать нового корпоративного клиента) |
| `order[contact]` | `object (SerializedRelationAbstractCustomer)` |     | Контактное лицо |
| `order[contact][id]` | `integer` |     | Внутренний ID клиента |
| `order[contact][externalId]` | `string` |     | Внешний ID клиента |
| `order[contact][browserId]` | `string` |     | Идентификатор устройства в Collector |
| `order[contact][site]` | `string` |     | Код магазина, необходим при передаче externalId |
| `order[company]` | `object (EntityWithExternalIdInput)` |     | Компания |
| `order[company][id]` | `integer` |     | ID  |
| `order[company][externalId]` | `string` |     | Внешний ID |
| `order[managerId]` | `integer` |     | Менеджер, прикрепленный к заказу |
| `order[status]` | `string` |     | Статус заказа |
| `order[items][]` | `array of objects (SerializedOrderProduct)` |     |     |
| `order[items][][markingCodes][]` | `array of strings` |     | Коды маркировки |
| `order[items][][id]` | `integer` |     | ID позиции в заказе |
| `order[items][][initialPrice]` | `double` |     | Цена товара/SKU (в валюте объекта) |
| `order[items][][discountManualAmount]` | `double` |     | Денежная скидка на единицу товара (в валюте объекта) |
| `order[items][][discountManualPercent]` | `double` |     | Процентная скидка на единицу товара |
| `order[items][][vatRate]` | `string` |     | Ставка НДС |
| `order[items][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата создания позиции в системе |
| `order[items][][quantity]` | `float` |     | Количество |
| `order[items][][comment]` | `string` |     | Комментарий к позиции в заказе |
| `order[items][][properties][]` | `array` |     | \[массив\] Дополнительные свойства позиции в заказе |
| `order[items][][properties][][code]` | `string` | `{not blank}{match: /^[a-zA-Z0-9_][a-zA-Z0-9_\-:]*$/D}}` | Код свойства (не обязательное поле, код может передаваться в ключе свойства) |
| `order[items][][properties][][name]` | `string` | `{not blank}` | Имя свойства |
| `order[items][][properties][][value]` | `string` | `{not blank}` | Значение свойства |
| `order[items][][purchasePrice]` | `double` |     | Закупочная цена (в базовой валюте) |
| `order[items][][ordering]` | `integer` |     | Порядок |
| `order[items][][offer]` | `object (SerializedOrderProductOffer)` |     | Торговое предложение |
| `order[items][][offer][id]` | `integer` |     | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `order[items][][offer][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `order[items][][productName]` | `string` |     | Название товара |
| `order[items][][status]` | `string` |     | Статус позиции в заказе |
| `order[items][][priceType]` | `object (PriceType)` |     | Тип цены |
| `order[items][][priceType][code]` | `string` |     | Код типа цены |
| `order[items][][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` |     | Код |
| `order[items][][externalIds][][value]` | `string` |     | Значение |
| `order[delivery]` | `object (SerializedOrderDelivery)` |     | Данные о доставке |
| `order[delivery][code]` | `string` |     | Код типа доставки |
| `order[delivery][data]` | object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData) |     | Данные службы доставки, подключенной через API |
| `order[delivery][data][externalId]` | `string` |     | Идентификатор в службе доставки |
| `order[delivery][data][trackNumber]` | `string` |     | Номер отправления (поле `deprecated` на запись) |
| `order[delivery][data][locked]` | `boolean` |     | Не синхронизировать со службой доставки |
| `order[delivery][data][tariff]` | `string` |     | Код тарифа |
| `order[delivery][data][pickuppointId]` | `string` |     | Идентификатор пункта самовывоза |
| `order[delivery][data][payerType]` | `string` |     | Плательщик за доставку |
| `order[delivery][data][shipmentpointId]` | `string` |     | Идентификатор терминала отгрузки |
| `order[delivery][data][extraData]` | `array` |     | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `order[delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |     |
| `order[delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `order[delivery][data][itemDeclaredValues][][value]` | `double` |     | Объявленная стоимость товара |
| `order[delivery][data][packages][]` | `array of objects (Package)` |     | Упаковки |
| `order[delivery][data][packages][][packageId]` | `string` |     | Идентификатор упаковки |
| `order[delivery][data][packages][][weight]` | `double` |     | Вес г. |
| `order[delivery][data][packages][][length]` | `integer` |     | Длина мм. |
| `order[delivery][data][packages][][width]` | `integer` |     | Ширина мм. |
| `order[delivery][data][packages][][height]` | `integer` |     | Высота мм. |
| `order[delivery][data][packages][][items][]` | `array of objects (PackageItem)` |     | Содержимое упаковки |
| `order[delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` |     | Позиция в заказе |
| `order[delivery][data][packages][][items][][orderProduct][id]` | `integer` |     | ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalId]` | `string` |     | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` |     | Код |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` |     | Значение |
| `order[delivery][data][packages][][items][][quantity]` | `double` |     | Количество товара в упаковке |
| `order[delivery][service]` | `object (SerializedDeliveryService)` |     |     |
| `order[delivery][service][name]` | `string` |     | Название |
| `order[delivery][service][code]` | `string` |     | Символьный код |
| `order[delivery][service][active]` | `boolean` |     | Статус активности |
| `order[delivery][service][deliveryType]` | `string` |     | Тип доставки |
| `order[delivery][cost]` | `double` |     | Стоимость доставки |
| `order[delivery][netCost]` | `double` |     | Себестоимость доставки |
| `order[delivery][date]` | `DateTime` | `Y-m-d` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` |     | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | `H:i` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | `H:i` | Время "до" |
| `order[delivery][time][custom]` | `string` |     | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` |     | Адрес доставки |
| `order[delivery][address][index]` | `string` |     | Индекс |
| `order[delivery][address][countryIso]` | `string` |     | ISO код страны |
| `order[delivery][address][region]` | `string` |     | Регион |
| `order[delivery][address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` |     | Город |
| `order[delivery][address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` |     | Тип населенного пункта |
| `order[delivery][address][street]` | `string` |     | Улица |
| `order[delivery][address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` |     | Тип улицы |
| `order[delivery][address][building]` | `string` |     | Дом |
| `order[delivery][address][flat]` | `string` |     | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` |     | Этаж |
| `order[delivery][address][block]` | `integer` |     | Подъезд |
| `order[delivery][address][house]` | `string` |     | Строение |
| `order[delivery][address][housing]` | `string` |     | Корпус |
| `order[delivery][address][metro]` | `string` |     | Метро |
| `order[delivery][address][notes]` | `string` |     | Примечания к адресу |
| `order[delivery][address][text]` | `string` |     | Адрес в текстовом виде |
| `order[delivery][vatRate]` | `string` |     | Ставка НДС |
| `order[source]` | `object (SerializedSource)` |     | Источник заказа |
| `order[source][source]` | `string` |     | Источник |
| `order[source][medium]` | `string` |     | Канал |
| `order[source][campaign]` | `string` |     | Кампания |
| `order[source][keyword]` | `string` |     | Ключевое слово |
| `order[source][content]` | `string` |     | Содержание кампании |
| `order[shipmentStore]` | `string` |     | Склад отгрузки |
| `order[loyaltyEventDiscountId]` | `integer` |     | ID скидки по событию программы лояльности |
| `order[applyRound]` | `boolean` |     | Применять настройку округления стоимости заказа |
| `order[isFromCart]` | `boolean` |     | Заказ создан из корзины |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID заказа |
| `order` | `object (Order)` | Заказ |
| `order[slug]` | `custom handler result for (int)` | `deprecated` Символьный код |
| `order[bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[summ]` | `double` | Сумма по товарам/услугам (в валюте объекта) |
| `order[currency]` | `string` | Валюта |
| `order[id]` | `integer` | ID заказа |
| `order[number]` | `string` | Номер заказа |
| `order[externalId]` | `string` | Внешний ID заказа |
| `order[orderType]` | `string` | Тип заказа |
| `order[orderMethod]` | `string` | Способ оформления |
| `order[privilegeType]` | `string` | Тип привилегии |
| `order[countryIso]` | `string` | ISO код страны |
| `order[createdAt]` | `DateTime` | Дата оформления заказа |
| `order[statusUpdatedAt]` | `DateTime` | Дата последнего изменения статуса |
| `order[totalSumm]` | `double` | Общая сумма с учетом скидки (в валюте объекта) |
| `order[prepaySum]` | `double` | Оплаченная сумма (в валюте объекта) |
| `order[purchaseSumm]` | `double` | Общая стоимость закупки (в базовой валюте) |
| `order[personalDiscountPercent]` | `double` | Персональная скидка на заказ |
| `order[loyaltyLevel]` | `object (LoyaltyLevel)` | Уровень участия в программе лояльности |
| `order[loyaltyLevel][id]` | `integer` | ID уровня |
| `order[loyaltyLevel][name]` | `string` | Название уровня |
| `order[loyaltyEventDiscount]` | `object (LoyaltyEventDiscount)` | Скидка по событию программы лояльности |
| `order[loyaltyEventDiscount][id]` | `integer` | ID  |
| `order[mark]` | `integer` | Оценка заказа |
| `order[markDatetime]` | `DateTime` | Дата и время получение оценки от покупателя |
| `order[lastName]` | `string` | Фамилия |
| `order[firstName]` | `string` | Имя |
| `order[patronymic]` | `string` | Отчество |
| `order[phone]` | `string` | Телефон |
| `order[additionalPhone]` | `string` | Дополнительный телефон |
| `order[email]` | `string` | E-mail |
| `order[call]` | `boolean` | Требуется позвонить |
| `order[expired]` | `boolean` | Просрочен |
| `order[customerComment]` | `string` | Комментарий клиента |
| `order[managerComment]` | `string` | Комментарий оператора |
| `order[managerId]` | `integer` | Менеджер, прикрепленный к заказу |
| `order[customer]` | `object (Customer)object (CustomerCorporate)` | Клиент |
| `order[customer][type]` | `string` | Тип клиента |
| `order[customer][id]` | `integer` | ID клиента |
| `order[customer][externalId]` | `string` | Внешний ID клиента |
| `order[customer][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[customer][createdAt]` | `DateTime` | Создан |
| `order[customer][managerId]` | `integer` | Менеджер клиента |
| `order[customer][vip]` | `boolean` | Важный клиент |
| `order[customer][bad]` | `boolean` | Плохой клиент |
| `order[customer][site]` | `string` | Магазин, с которого пришел клиент |
| `order[customer][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[customer][contragent][contragentType]` | `string` | Тип контрагента |
| `order[customer][contragent][legalName]` | `string` | Полное наименование |
| `order[customer][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[customer][contragent][INN]` | `string` | ИНН |
| `order[customer][contragent][OKPO]` | `string` | ОКПО |
| `order[customer][contragent][KPP]` | `string` | КПП |
| `order[customer][contragent][OGRN]` | `string` | ОГРН |
| `order[customer][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[customer][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[customer][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[customer][contragent][BIK]` | `string` | БИК |
| `order[customer][contragent][bank]` | `string` | Банк |
| `order[customer][contragent][bankAddress]` | `string` | Адрес банка |
| `order[customer][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[customer][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[customer][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[customer][tags][][color]` | `string` |     |
| `order[customer][tags][][name]` | `string` |     |
| `order[customer][tags][][colorCode]` | `string` |     |
| `order[customer][tags][][attached]` | `boolean` |     |
| `order[customer][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[customer][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[customer][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[customer][personalDiscount]` | `double` | Персональная скидка |
| `order[customer][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[customer][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[customer][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[customer][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[customer][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[customer][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[customer][ordersCount]` | `integer` | Количество заказов |
| `order[customer][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[customer][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[customer][address][id]` | `integer` | ID адреса |
| `order[customer][address][index]` | `string` | Индекс |
| `order[customer][address][countryIso]` | `string` | ISO код страны |
| `order[customer][address][region]` | `string` | Регион |
| `order[customer][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[customer][address][city]` | `string` | Город |
| `order[customer][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[customer][address][cityType]` | `string` | Тип населенного пункта |
| `order[customer][address][street]` | `string` | Улица |
| `order[customer][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[customer][address][streetType]` | `string` | Тип улицы |
| `order[customer][address][building]` | `string` | Дом |
| `order[customer][address][flat]` | `string` | Номер квартиры/офиса |
| `order[customer][address][floor]` | `integer` | Этаж |
| `order[customer][address][block]` | `integer` | Подъезд |
| `order[customer][address][house]` | `string` | Строение |
| `order[customer][address][housing]` | `string` | Корпус |
| `order[customer][address][metro]` | `string` | Метро |
| `order[customer][address][notes]` | `string` | Примечания к адресу |
| `order[customer][address][text]` | `string` | Адрес в текстовом виде |
| `order[customer][address][externalId]` | `string` | Внешний ID |
| `order[customer][address][name]` | `string` | Наменование адреса |
| `order[customer][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[customer][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[customer][segments][][code]` | `string` | Символьный код |
| `order[customer][segments][][name]` | `string` | Название сегмента |
| `order[customer][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[customer][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[customer][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[customer][segments][][active]` | `boolean` | Активность сегмента |
| `order[customer][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[customer][firstName]` | `string` | Имя |
| `order[customer][lastName]` | `string` | Фамилия |
| `order[customer][patronymic]` | `string` | Отчество |
| `order[customer][sex]` | `string` | Пол |
| `order[customer][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[customer][email]` | `string` | E-mail |
| `order[customer][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[customer][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[customer][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[customer][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[customer][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[customer][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[customer][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[customer][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[customer][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[customer][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[customer][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[customer][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[customer][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[customer][phones][][number]` | `string` | Номер телефона |
| `order[customer][birthday]` | `DateTime` | День рождения |
| `order[customer][source]` | `object (SerializedSource)` | Источник клиента |
| `order[customer][source][source]` | `string` | Источник |
| `order[customer][source][medium]` | `string` | Канал |
| `order[customer][source][campaign]` | `string` | Кампания |
| `order[customer][source][keyword]` | `string` | Ключевое слово |
| `order[customer][source][content]` | `string` | Содержание кампании |
| `order[customer][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[customer][mgCustomers][][id]` | `integer` | ID клиента |
| `order[customer][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[customer][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[customer][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[customer][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[customer][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[customer][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[customer][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[customer][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[customer][photoUrl]` | `string` | URL фотографии |
| `order[contact]` | `object (Customer)` | Контактное лицо |
| `order[contact][type]` | `string` | Тип клиента |
| `order[contact][id]` | `integer` | ID клиента |
| `order[contact][externalId]` | `string` | Внешний ID клиента |
| `order[contact][isContact]` | `boolean` | Клиент является контактным лицом (создан как контактное лицо и на него нет оформленных заказов) |
| `order[contact][createdAt]` | `DateTime` | Создан |
| `order[contact][managerId]` | `integer` | Менеджер клиента |
| `order[contact][vip]` | `boolean` | Важный клиент |
| `order[contact][bad]` | `boolean` | Плохой клиент |
| `order[contact][site]` | `string` | Магазин, с которого пришел клиент |
| `order[contact][contragent]` | `object (CustomerContragent)` | `deprecated` Реквизиты (Поля объекта следует использовать только при неактивированной функциональности "Корпоративные клиенты") |
| `order[contact][contragent][contragentType]` | `string` | Тип контрагента |
| `order[contact][contragent][legalName]` | `string` | Полное наименование |
| `order[contact][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contact][contragent][INN]` | `string` | ИНН |
| `order[contact][contragent][OKPO]` | `string` | ОКПО |
| `order[contact][contragent][KPP]` | `string` | КПП |
| `order[contact][contragent][OGRN]` | `string` | ОГРН |
| `order[contact][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contact][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contact][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contact][contragent][BIK]` | `string` | БИК |
| `order[contact][contragent][bank]` | `string` | Банк |
| `order[contact][contragent][bankAddress]` | `string` | Адрес банка |
| `order[contact][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contact][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[contact][tags][]` | `array of objects (CustomerTagLink)` | \[массив\] Теги |
| `order[contact][tags][][color]` | `string` |     |
| `order[contact][tags][][name]` | `string` |     |
| `order[contact][tags][][colorCode]` | `string` |     |
| `order[contact][tags][][attached]` | `boolean` |     |
| `order[contact][firstClientId]` | `string` | Первая метка клиента Google Analytics |
| `order[contact][lastClientId]` | `string` | Последняя метка клиента Google Analytics |
| `order[contact][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contact][personalDiscount]` | `double` | Персональная скидка |
| `order[contact][cumulativeDiscount]` | `double` | `deprecated` Накопительная скидка (Недоступно, начиная с 8 версии системы) |
| `order[contact][discountCardNumber]` | `string` | Номер дисконтной карты |
| `order[contact][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[contact][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[contact][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[contact][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[contact][ordersCount]` | `integer` | Количество заказов |
| `order[contact][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[contact][address]` | `object (CustomerAddress)` | Адрес клиента |
| `order[contact][address][id]` | `integer` | ID адреса |
| `order[contact][address][index]` | `string` | Индекс |
| `order[contact][address][countryIso]` | `string` | ISO код страны |
| `order[contact][address][region]` | `string` | Регион |
| `order[contact][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[contact][address][city]` | `string` | Город |
| `order[contact][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[contact][address][cityType]` | `string` | Тип населенного пункта |
| `order[contact][address][street]` | `string` | Улица |
| `order[contact][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[contact][address][streetType]` | `string` | Тип улицы |
| `order[contact][address][building]` | `string` | Дом |
| `order[contact][address][flat]` | `string` | Номер квартиры/офиса |
| `order[contact][address][floor]` | `integer` | Этаж |
| `order[contact][address][block]` | `integer` | Подъезд |
| `order[contact][address][house]` | `string` | Строение |
| `order[contact][address][housing]` | `string` | Корпус |
| `order[contact][address][metro]` | `string` | Метро |
| `order[contact][address][notes]` | `string` | Примечания к адресу |
| `order[contact][address][text]` | `string` | Адрес в текстовом виде |
| `order[contact][address][externalId]` | `string` | Внешний ID |
| `order[contact][address][name]` | `string` | Наменование адреса |
| `order[contact][segments][]` | `array of objects (Segment)` | \[массив\] Сегменты |
| `order[contact][segments][][id]` | `integer` | Внутренний ID сегмента |
| `order[contact][segments][][code]` | `string` | Символьный код |
| `order[contact][segments][][name]` | `string` | Название сегмента |
| `order[contact][segments][][createdAt]` | `DateTime` | Дата создания сегмента |
| `order[contact][segments][][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `order[contact][segments][][customersCount]` | `integer` | Количество клиентов в сегменте |
| `order[contact][segments][][active]` | `boolean` | Активность сегмента |
| `order[contact][maturationTime]` | `integer` | Время «созревания», в секундах |
| `order[contact][firstName]` | `string` | Имя |
| `order[contact][lastName]` | `string` | Фамилия |
| `order[contact][patronymic]` | `string` | Отчество |
| `order[contact][sex]` | `string` | Пол |
| `order[contact][presumableSex]` | `string` | Предполагаемый пол на основе ФИО |
| `order[contact][email]` | `string` | E-mail |
| `order[contact][emailMarketingUnsubscribedAt]` | `DateTime` | `deprecated` Дата отписки от email рассылок |
| `order[contact][customerSubscriptions][]` | `array of objects (CustomerSubscription)` | Подписки |
| `order[contact][customerSubscriptions][][subscription]` | `object (Subscription)` | Категория подписки |
| `order[contact][customerSubscriptions][][subscription][id]` | `integer` | ID категории подписки |
| `order[contact][customerSubscriptions][][subscription][channel]` | `string` | Канал |
| `order[contact][customerSubscriptions][][subscription][name]` | `string` | Название |
| `order[contact][customerSubscriptions][][subscription][code]` | `string` | Символьный код |
| `order[contact][customerSubscriptions][][subscription][active]` | `boolean` | Статус активности |
| `order[contact][customerSubscriptions][][subscription][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `order[contact][customerSubscriptions][][subscription][ordering]` | `integer` | Порядок |
| `order[contact][customerSubscriptions][][subscribed]` | `boolean` | Активность подписки |
| `order[contact][customerSubscriptions][][changedAt]` | `DateTime` | Дата изменения флага активности |
| `order[contact][phones][]` | `array of objects (CustomerPhone)` | Телефоны |
| `order[contact][phones][][number]` | `string` | Номер телефона |
| `order[contact][birthday]` | `DateTime` | День рождения |
| `order[contact][source]` | `object (SerializedSource)` | Источник клиента |
| `order[contact][source][source]` | `string` | Источник |
| `order[contact][source][medium]` | `string` | Канал |
| `order[contact][source][campaign]` | `string` | Кампания |
| `order[contact][source][keyword]` | `string` | Ключевое слово |
| `order[contact][source][content]` | `string` | Содержание кампании |
| `order[contact][mgCustomers][]` | `array of objects (MGCustomer)` | Клиенты MessageGateway |
| `order[contact][mgCustomers][][id]` | `integer` | ID клиента |
| `order[contact][mgCustomers][][externalId]` | `integer` | Внешний ID MessageGateway клиента |
| `order[contact][mgCustomers][][mgChannel]` | `object (MGChannel)` | MessageGateway канал |
| `order[contact][mgCustomers][][mgChannel][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `order[contact][mgCustomers][][mgChannel][id]` | `integer` | ID канала |
| `order[contact][mgCustomers][][mgChannel][externalId]` | `integer` | Внешний ID канала |
| `order[contact][mgCustomers][][mgChannel][type]` | `string` | Тип канала |
| `order[contact][mgCustomers][][mgChannel][active]` | `boolean` | Активность канала |
| `order[contact][mgCustomers][][mgChannel][name]` | `string` | Название канала |
| `order[contact][photoUrl]` | `string` | URL фотографии |
| `order[company]` | `object (Company)` | Компания |
| `order[company][id]` | `integer` | ID компании |
| `order[company][externalId]` | `string` | Внешний ID компании |
| `order[company][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `order[company][customer][site]` | `string` | Символьный код магазина |
| `order[company][customer][id]` | `integer` | Внутренний ID клиента |
| `order[company][customer][externalId]` | `string` | Внешний ID клиента |
| `order[company][customer][type]` | `string` | Тип клиента |
| `order[company][active]` | `boolean` | Активность |
| `order[company][name]` | `string` | Наименование |
| `order[company][brand]` | `string` | Бренд |
| `order[company][site]` | `string` | Сайт компании |
| `order[company][createdAt]` | `DateTime` | Дата создания |
| `order[company][contragent]` | `object (CompanyContragent)` | Реквизиты |
| `order[company][contragent][contragentType]` | `string` | Тип контрагента |
| `order[company][contragent][legalName]` | `string` | Полное наименование |
| `order[company][contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[company][contragent][INN]` | `string` | ИНН |
| `order[company][contragent][OKPO]` | `string` | ОКПО |
| `order[company][contragent][KPP]` | `string` | КПП |
| `order[company][contragent][OGRN]` | `string` | ОГРН |
| `order[company][contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[company][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[company][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[company][contragent][BIK]` | `string` | БИК |
| `order[company][contragent][bank]` | `string` | Банк |
| `order[company][contragent][bankAddress]` | `string` | Адрес банка |
| `order[company][contragent][corrAccount]` | `string` | Корр. счёт |
| `order[company][contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[company][address]` | `object (CustomerAddress)` | Адрес |
| `order[company][address][id]` | `integer` | ID адреса |
| `order[company][address][index]` | `string` | Индекс |
| `order[company][address][countryIso]` | `string` | ISO код страны |
| `order[company][address][region]` | `string` | Регион |
| `order[company][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[company][address][city]` | `string` | Город |
| `order[company][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[company][address][cityType]` | `string` | Тип населенного пункта |
| `order[company][address][street]` | `string` | Улица |
| `order[company][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[company][address][streetType]` | `string` | Тип улицы |
| `order[company][address][building]` | `string` | Дом |
| `order[company][address][flat]` | `string` | Номер квартиры/офиса |
| `order[company][address][floor]` | `integer` | Этаж |
| `order[company][address][block]` | `integer` | Подъезд |
| `order[company][address][house]` | `string` | Строение |
| `order[company][address][housing]` | `string` | Корпус |
| `order[company][address][metro]` | `string` | Метро |
| `order[company][address][notes]` | `string` | Примечания к адресу |
| `order[company][address][text]` | `string` | Адрес в текстовом виде |
| `order[company][address][externalId]` | `string` | Внешний ID |
| `order[company][address][name]` | `string` | Наменование адреса |
| `order[company][avgMarginSumm]` | `float` | Средняя валовая прибыль по заказам клиента (в базовой валюте) |
| `order[company][marginSumm]` | `float` | LTV (в базовой валюте) |
| `order[company][totalSumm]` | `float` | Общая сумма заказов (в базовой валюте) |
| `order[company][averageSumm]` | `float` | Средняя сумма заказа (в базовой валюте) |
| `order[company][costSumm]` | `float` | Сумма расходов (в базовой валюте) |
| `order[company][ordersCount]` | `integer` | Количество заказов |
| `order[company][customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[contragent]` | `object (OrderContragent)` | Реквизиты |
| `order[contragent][contragentType]` | `string` | Тип контрагента |
| `order[contragent][legalName]` | `string` | Полное наименование |
| `order[contragent][legalAddress]` | `string` | Адрес регистрации |
| `order[contragent][INN]` | `string` | ИНН |
| `order[contragent][OKPO]` | `string` | ОКПО |
| `order[contragent][KPP]` | `string` | КПП |
| `order[contragent][OGRN]` | `string` | ОГРН |
| `order[contragent][OGRNIP]` | `string` | ОГРНИП |
| `order[contragent][certificateNumber]` | `string` | Номер свидетельства |
| `order[contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `order[contragent][BIK]` | `string` | БИК |
| `order[contragent][bank]` | `string` | Банк |
| `order[contragent][bankAddress]` | `string` | Адрес банка |
| `order[contragent][corrAccount]` | `string` | Корр. счёт |
| `order[contragent][bankAccount]` | `string` | Расчётный счёт |
| `order[delivery]` | `object (SerializedOrderDelivery)` | Данные о доставке |
| `order[delivery][code]` | `string` | Код типа доставки |
| `order[delivery][integrationCode]` | `string` | Интеграционный код типа доставки |
| `order[delivery][data]` | `object (GenericData)object (CourierData)object (NewPostData)object (DDeliveryData)object (KazPostData)` | Данные службы доставки, подключенной через API |
| `order[delivery][data][externalId]` | `string` | Идентификатор в службе доставки |
| `order[delivery][data][trackNumber]` | `string` | Номер отправления (поле `deprecated` на запись) |
| `order[delivery][data][status]` | `string` | Код статуса доставки |
| `order[delivery][data][locked]` | `boolean` | Не синхронизировать со службой доставки |
| `order[delivery][data][pickuppointAddress]` | `string` | Адрес пункта самовывоза |
| `order[delivery][data][days]` | `string` | Ориентировочный срок доставки |
| `order[delivery][data][statusText]` | `string` | Наименование статуса доставки |
| `order[delivery][data][statusDate]` | `DateTime` | Дата статуса доставки |
| `order[delivery][data][tariff]` | `string` | Код тарифа |
| `order[delivery][data][tariffName]` | `string` | Наименование тарифа |
| `order[delivery][data][pickuppointId]` | `string` | Идентификатор пункта самовывоза |
| `order[delivery][data][pickuppointSchedule]` | `string` | Режим работы пункта самовывоза |
| `order[delivery][data][pickuppointPhone]` | `string` | Телефон пункта самовывоза |
| `order[delivery][data][payerType]` | `string` | Плательщик за доставку |
| `order[delivery][data][statusComment]` | `string` | Комментарий к статусу доставки |
| `order[delivery][data][cost]` | `float` | Стоимость доставки, полученная из службы доставки (в валюте объекта) |
| `order[delivery][data][minTerm]` | `integer` | Минимальный срок доставки |
| `order[delivery][data][maxTerm]` | `integer` | Максимальный срок доставки |
| `order[delivery][data][shipmentpointId]` | `string` | Идентификатор терминала отгрузки |
| `order[delivery][data][shipmentpointName]` | `string` | Наименование терминала отгрузки |
| `order[delivery][data][shipmentpointAddress]` | `string` | Адрес терминала отгрузки |
| `order[delivery][data][shipmentpointSchedule]` | `string` | Режим работы терминала отгрузки |
| `order[delivery][data][shipmentpointPhone]` | `string` | Телефон терминала отгрузки |
| `order[delivery][data][shipmentpointCoordinateLatitude]` | `string` | Координаты терминала отгрузки, широта |
| `order[delivery][data][shipmentpointCoordinateLongitude]` | `string` | Координаты терминала отгрузки, долгота |
| `order[delivery][data][pickuppointName]` | `string` | Наименование пункта самовывоза |
| `order[delivery][data][pickuppointCoordinateLatitude]` | `string` | Координаты ПВЗ, широта |
| `order[delivery][data][pickuppointCoordinateLongitude]` | `string` | Координаты ПВЗ, долгота |
| `order[delivery][data][extraData]` | `array` | Дополнительные данные доставки (`deliveryDataField.code => значение`) |
| `order[delivery][data][itemDeclaredValues][]` | `array of objects (DeclaredValueItem)` |     |
| `order[delivery][data][itemDeclaredValues][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][itemDeclaredValues][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][itemDeclaredValues][][value]` | `double` | Объявленная стоимость товара |
| `order[delivery][data][packages][]` | `array of objects (Package)` | Упаковки |
| `order[delivery][data][packages][][packageId]` | `string` | Идентификатор упаковки |
| `order[delivery][data][packages][][weight]` | `double` | Вес г. |
| `order[delivery][data][packages][][length]` | `integer` | Длина мм. |
| `order[delivery][data][packages][][width]` | `integer` | Ширина мм. |
| `order[delivery][data][packages][][height]` | `integer` | Высота мм. |
| `order[delivery][data][packages][][items][]` | `array of objects (PackageItem)` | Содержимое упаковки |
| `order[delivery][data][packages][][items][][orderProduct]` | `object (PackageItemOrderProduct)` | Позиция в заказе |
| `order[delivery][data][packages][][items][][orderProduct][id]` | `integer` | ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][code]` | `string` | Код |
| `order[delivery][data][packages][][items][][orderProduct][externalIds][][value]` | `string` | Значение |
| `order[delivery][data][packages][][items][][quantity]` | `double` | Количество товара в упаковке |
| `order[delivery][service]` | `object (SerializedDeliveryService)` |     |
| `order[delivery][service][name]` | `string` | Название |
| `order[delivery][service][code]` | `string` | Символьный код |
| `order[delivery][service][active]` | `boolean` | Статус активности |
| `order[delivery][cost]` | `double` | Стоимость доставки |
| `order[delivery][netCost]` | `double` | Себестоимость доставки |
| `order[delivery][date]` | `DateTime` | Дата доставки |
| `order[delivery][time]` | `object (TimeInterval)` | Информация о временном диапазоне |
| `order[delivery][time][from]` | `DateTime` | Время "с" |
| `order[delivery][time][to]` | `DateTime` | Время "до" |
| `order[delivery][time][custom]` | `string` | Временной диапазон в свободной форме |
| `order[delivery][address]` | `object (OrderDeliveryAddress)` | Адрес доставки |
| `order[delivery][address][index]` | `string` | Индекс |
| `order[delivery][address][countryIso]` | `string` | ISO код страны |
| `order[delivery][address][region]` | `string` | Регион |
| `order[delivery][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `order[delivery][address][city]` | `string` | Город |
| `order[delivery][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `order[delivery][address][cityType]` | `string` | Тип населенного пункта |
| `order[delivery][address][street]` | `string` | Улица |
| `order[delivery][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `order[delivery][address][streetType]` | `string` | Тип улицы |
| `order[delivery][address][building]` | `string` | Дом |
| `order[delivery][address][flat]` | `string` | Номер квартиры/офиса |
| `order[delivery][address][floor]` | `integer` | Этаж |
| `order[delivery][address][block]` | `integer` | Подъезд |
| `order[delivery][address][house]` | `string` | Строение |
| `order[delivery][address][housing]` | `string` | Корпус |
| `order[delivery][address][metro]` | `string` | Метро |
| `order[delivery][address][notes]` | `string` | Примечания к адресу |
| `order[delivery][address][text]` | `string` | Адрес в текстовом виде |
| `order[delivery][vatRate]` | `string` | Ставка НДС |
| `order[site]` | `string` | Магазин |
| `order[status]` | `string` | Статус заказа |
| `order[statusComment]` | `string` | Комментарий к последнему изменению статуса |
| `order[source]` | `object (SerializedSource)` | Источник заказа |
| `order[source][source]` | `string` | Источник |
| `order[source][medium]` | `string` | Канал |
| `order[source][campaign]` | `string` | Кампания |
| `order[source][keyword]` | `string` | Ключевое слово |
| `order[source][content]` | `string` | Содержание кампании |
| `order[items][]` | `array of objects (OrderProduct)` | Позиция в заказе |
| `order[items][][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `order[items][][bonusesChargeTotal]` | `double` | Количество списанных бонусов |
| `order[items][][bonusesCreditTotal]` | `double` | Количество начисленных бонусов |
| `order[items][][markingCodes][]` | `array of strings` | Коды маркировки |
| `order[items][][id]` | `integer` | ID позиции в заказе |
| `order[items][][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `order[items][][externalIds][][code]` | `string` | Код |
| `order[items][][externalIds][][value]` | `string` | Значение |
| `order[items][][priceType]` | `object (PriceType)` | Тип цены |
| `order[items][][priceType][code]` | `string` | Код типа цены |
| `order[items][][initialPrice]` | `double` | Цена товара/SKU (в валюте объекта) |
| `order[items][][discounts][]` | `array of objects (AbstractDiscount)` | Массив скидок |
| `order[items][][discounts][][type]` | `string` | Тип скидки. Возможные значения:   `manual_order` - Разовая скидка на заказ;   `manual_product` - Дополнительная скидка на товар;   `loyalty_level` - Скидка по уровню программы лояльности;   `loyalty_event` - Скидка по событию программы лояльности;   `personal` - Персональная скидка;   `bonus_charge` - Списание бонусов ПЛ;   `round` - Скидка от округления |
| `order[items][][discounts][][amount]` | `float` | Сумма скидки |
| `order[items][][discountTotal]` | `double` | Итоговая денежная скидка на единицу товара c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][]` | `array of objects (OrderProductPriceItem)` | Набор итоговых цен реализации с указанием количества |
| `order[items][][prices][][price]` | `float` | Итоговая цена c учетом всех скидок на товар и заказ (в валюте объекта) |
| `order[items][][prices][][quantity]` | `float` | Количество товара по заданной цене |
| `order[items][][vatRate]` | `string` | Ставка НДС |
| `order[items][][createdAt]` | `DateTime` | Дата создания позиции в системе |
| `order[items][][quantity]` | `float` | Количество |
| `order[items][][status]` | `string` | Статус позиции в заказе |
| `order[items][][comment]` | `string` | Комментарий к позиции в заказе |
| `order[items][][offer]` | `object (Offer)` | Торговое предложение |
| `order[items][][offer][displayName]` | `string` | Название SKU |
| `order[items][][offer][id]` | `integer` | ID торгового предложения |
| `order[items][][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `order[items][][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `order[items][][offer][name]` | `string` | Название |
| `order[items][][offer][article]` | `string` | Артикул |
| `order[items][][offer][vatRate]` | `string` | Ставка НДС |
| `order[items][][offer][properties]` | `array` | Свойства SKU |
| `order[items][][offer][unit]` | `object (Unit)` | Единица измерения |
| `order[items][][offer][unit][code]` | `string` | Символьный код |
| `order[items][][offer][unit][name]` | `string` | Название |
| `order[items][][offer][unit][sym]` | `string` | Краткое обозначение |
| `order[items][][offer][barcode]` | `string` | Штрих-код |
| `order[items][][isCanceled]` | `boolean` | Данная позиция в заказе является отменной |
| `order[items][][properties]` | `array` | \[массив\] Дополнительные свойства позиции в заказе |
| `order[items][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `order[items][][ordering]` | `integer` | Порядок |
| `order[fullPaidAt]` | `DateTime` | Дата полной оплаты |
| `order[payments][]` | `array of objects (Payment)` | Платежи |
| `order[payments][][id]` | `integer` | Внутренний ID |
| `order[payments][][status]` | `string` | Статус оплаты |
| `order[payments][][type]` | `string` | Тип оплаты |
| `order[payments][][externalId]` | `string` | Внешний ID платежа |
| `order[payments][][amount]` | `double` | Сумма платежа (в валюте объекта) |
| `order[payments][][paidAt]` | `DateTime` | Дата оплаты |
| `order[payments][][comment]` | `string` | Комментарий |
| `order[fromApi]` | `boolean` | Заказ поступил через API |
| `order[weight]` | `double` | Вес |
| `order[length]` | `integer` | Длина |
| `order[width]` | `integer` | Ширина |
| `order[height]` | `integer` | Высота |
| `order[shipmentStore]` | `string` | Склад отгрузки |
| `order[shipmentDate]` | `DateTime` | Дата отгрузки |
| `order[shipped]` | `boolean` | Заказ отгружен |
| `order[links][]` | `array of objects (OrderLink)` | Связь заказов |
| `order[links][][order]` | `object (LinkedOrder)` | Связанный заказ |
| `order[links][][order][id]` | `integer` | ID связанного заказа |
| `order[links][][order][number]` | `string` | Номер связанного заказа |
| `order[links][][order][externalId]` | `string` | Внешний ID связанного заказа |
| `order[links][][createdAt]` | `DateTime` | Дата/время создания связи с заказом |
| `order[links][][comment]` | `string` | Комментарий |
| `order[customFields]` | `array` | Ассоциативный массив пользовательских полей |
| `order[clientId]` | `string` | Метка клиента Google Analytics |
| `state` | `string` | Состояние заказа (по умолчанию не возвращается) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заказ успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении заказа</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/{externalId}/plates/{plateId}/print

Получение файла печатной формы для заказа

Для доступа к методу необходимо разрешение `order_read`.

Метод позволяет скачать сгенерированную печатную форму для шаблона с `id={plateId}` в контексте указанного заказа. При скачивании содержимое файла отдаётся в виде потока, название файла отдаётся в HTTP заголовке `Content-Disposition`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalId` | `string` |     | ID заказа |
| `plateId` | `int` |     | ID печатной формы |

#### Параметры для фильтрации

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Параметр</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>by</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Шаблон</p></td><td colspan="1" rowspan="1"><p><code>id|externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Значение по умолчанию</p></td><td colspan="1" rowspan="1"><p><code>externalId</code></p></td></tr><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID заказа. По умолчанию externalId.</p></td></tr></tbody></table></div></blockquote></td></tr><tr><td colspan="1" rowspan="1"><p><code>site</code></p></td><td colspan="1" rowspan="1"><blockquote><div class="tableWrapper"><table style="min-width: 50px"><tbody><tr><td colspan="1" rowspan="1"><p>Описание</p></td><td colspan="1" rowspan="1"><p>Символьный код магазина</p></td></tr></tbody></table></div></blockquote></td></tr></tbody></table>

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при генерации печатной формы</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Заказ или печатная форма не найдены</p></li></ul></td></tr></tbody></table>

## Комплектация заказов

### GET /api/v5/orders/packs

Получение списка паков, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `order_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (OrderProductPackFilter)` |     |     |
| `filter[ids]` | `array of integers` |     | Массив ID комплектаций заказов |
| `filter[stores][]` | `array of strings` |     | Склад |
| `filter[itemId]` | `integer` | `{range: {>=0}}` | ID товара |
| `filter[offerXmlId]` | `string` | `{length: {max: 255}}` | Xml ID торгового предложения |
| `filter[offerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID торгового предложения |
| `filter[orderId]` | `integer` | `{range: {>=0}}` | ID заказа |
| `filter[orderExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID заказа |
| `filter[shipmentDateFrom]` | `DateTimeInterface` |     | Дата отгрузки от |
| `filter[shipmentDateTo]` | `DateTimeInterface` |     | Дата отгрузки до |
| `filter[invoiceNumber]` | `string` | `{length: {max: 255}}` | Номер счета-фактуры |
| `filter[deliveryNoteNumber]` | `string` | `{length: {max: 255}}` | Номер накладной |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `packs[]` | `array of objects (OrderProductPack)` | Пак - упаковка товаров, в рамках одной товарной позиции, с одного склада |
| `packs[][id]` | `integer` | ID  |
| `packs[][purchasePrice]` | `float` | Закупочная цена (в базовой валюте) |
| `packs[][quantity]` | `float` | Количество товара в упаковке |
| `packs[][unit]` | `object (Unit)` | Единица измерения |
| `packs[][unit][code]` | `string` | Символьный код |
| `packs[][unit][name]` | `string` | Название |
| `packs[][unit][sym]` | `string` | Краткое обозначение |
| `packs[][store]` | `string` | Склад |
| `packs[][item]` | `object (OrderProduct)` | Позиция в заказе |
| `packs[][item][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `packs[][item][id]` | `integer` | ID позиции в заказе |
| `packs[][item][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `packs[][item][order]` | `object (Order)` | Заказ |
| `packs[][item][order][id]` | `integer` | ID заказа |
| `packs[][item][offer]` | `object (Offer)` | Торговое предложение |
| `packs[][item][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `packs[][item][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `packs[][shipmentDate]` | `DateTime` | Дата забора пака |
| `packs[][invoiceNumber]` | `string` | Номер счет-фактуры |
| `packs[][deliveryNoteNumber]` | `string` | Номер товарной накладной |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/packs/create

Создание пака

Для доступа к методу необходимо разрешение `order_write`.

Метод создает пак и возвращает внутренний ID созданного пака. Создание пака для отменённой товарной позиции и услуги не разрешено. В этом случае будет выведено сообщение об ошибке.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `pack` | `object (SerializedOrderProductPack)` |     |     |
| `pack[purchasePrice]` | `float` |     | Закупочная цена (в базовой валюте) |
| `pack[quantity]` | `float` |     | Количество товара в упаковке |
| `pack[store]` | `string` |     | Склад |
| `pack[shipmentDate]` | `DateTime` |     | Дата забора пака |
| `pack[invoiceNumber]` | `string` |     | Номер счет-фактуры |
| `pack[deliveryNoteNumber]` | `string` |     | Номер товарной накладной |
| `pack[itemId]` | `integer` |     | ID позиции в заказе |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | ID пака |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пак успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании пака</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/packs/history

Получение истории комплектации заказа

Для доступа к методу необходимо разрешение `order_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Более подробно о работе с историей вы можете ознакомиться в [отдельной статье документации](https://docs.retailcrm.ru/Developers/API/APIFeatures/WorkingHistoryAPI).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (OrderProductPackHistoryFilterType)` |     |     |
| `filter[orderId]` | `integer` | `{range: {>=0}}` | Внутренний идентификатор заказа |
| `filter[sinceId]` | `integer` | `{range: {>=0}}` | Нижнее ограничение по идентификатору записи в истории (исключая границу) |
| `filter[orderExternalId]` | `string` | `{length: {max: 255}}` | Идентификатор заказа из магазина |
| `filter[startDate]` | `DateTime` | `Y-m-d H:i:s` | Время изменения (с) |
| `filter[endDate]` | `DateTime` | `Y-m-d H:i:s` | Время изменения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `generatedAt` | `DateTime` | Время формирования ответа |
| `history[]` | `array of objects (OrderProductPackHistory)` | Набор изменений в истории комплектации |
| `history[][id]` | `integer` | Внутренний идентификатор записи в истории |
| `history[][createdAt]` | `DateTime` | Дата внесения изменения |
| `history[][created]` | `boolean` | Флаг выводится для создания нового пакета товаров |
| `history[][deleted]` | `boolean` | Флаг выводится при удалении пакета товаров |
| `history[][field]` | `string` | Имя изменившегося поля |
| `history[][oldValue]` | `object (HistoryField)` | Старое значение |
| `history[][newValue]` | `object (HistoryField)` | Новое значение |
| `history[][pack]` | `object (OrderProductPack)` | Пак - упаковка товаров, в рамках одной товарной позиции, с одного склада |
| `history[][pack][id]` | `integer` | ID  |
| `history[][pack][purchasePrice]` | `float` | Закупочная цена (в базовой валюте) |
| `history[][pack][quantity]` | `float` | Количество товара в упаковке |
| `history[][pack][store]` | `object (Store)` | Склад |
| `history[][pack][store][code]` | `string` | Символьный код |
| `history[][pack][item]` | `object (OrderProduct)` | Позиция в заказе |
| `history[][pack][item][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `history[][pack][item][id]` | `integer` | ID позиции в заказе |
| `history[][pack][item][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `history[][pack][item][order]` | `object (Order)` | Заказ |
| `history[][pack][item][order][id]` | `integer` | ID заказа |
| `history[][pack][item][offer]` | `object (Offer)` | Торговое предложение |
| `history[][pack][item][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `history[][pack][item][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `history[][pack][shipmentDate]` | `DateTime` | Дата забора пака |
| `history[][pack][invoiceNumber]` | `string` | Номер счет-фактуры |
| `history[][pack][deliveryNoteNumber]` | `string` | Номер товарной накладной |
| `history[][source]` | `string` | Источник изменения |
| `history[][user]` | `object (User)` |     |
| `history[][user][id]` | `integer` | ID пользователя |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/orders/packs/{id}

Получение информации о паке

Для доступа к методу необходимо разрешение `order_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID пака |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pack` | `object (OrderProductPack)` | Пак - упаковка товаров, в рамках одной товарной позиции, с одного склада |
| `pack[id]` | `integer` | ID  |
| `pack[purchasePrice]` | `float` | Закупочная цена (в базовой валюте) |
| `pack[quantity]` | `float` | Количество товара в упаковке |
| `pack[unit]` | `object (Unit)` | Единица измерения |
| `pack[unit][code]` | `string` | Символьный код |
| `pack[unit][name]` | `string` | Название |
| `pack[unit][sym]` | `string` | Краткое обозначение |
| `pack[store]` | `string` | Склад |
| `pack[item]` | `object (OrderProduct)` | Позиция в заказе |
| `pack[item][externalId]` | `string` | `deprecated` Внешний ID позиции в заказе |
| `pack[item][id]` | `integer` | ID позиции в заказе |
| `pack[item][externalIds][]` | `array of objects (CodeValueModel)` | Внешние идентификаторы позиции в заказе |
| `pack[item][order]` | `object (Order)` | Заказ |
| `pack[item][order][id]` | `integer` | ID заказа |
| `pack[item][offer]` | `object (Offer)` | Торговое предложение |
| `pack[item][offer][externalId]` | `string` | ID торгового предложения в магазине |
| `pack[item][offer][xmlId]` | `string` | ID торгового предложения в складской системе |
| `pack[shipmentDate]` | `DateTime` | Дата забора пака |
| `pack[invoiceNumber]` | `string` | Номер счет-фактуры |
| `pack[deliveryNoteNumber]` | `string` | Номер товарной накладной |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пак не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/packs/{id}/delete

Удаление пака

Для доступа к методу необходимо разрешение `order_write`.

Удаление пака для отгруженного заказ недоступно.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пак успешно удалён</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при удалении пака</p></li></ul></td></tr></tbody></table>

### POST /api/v5/orders/packs/{id}/edit

Редактирование пака

Для доступа к методу необходимо разрешение `order_write`.

Для отгруженного заказа:

- уменьшение количества товара в паке `pack[quantity]` недоступно, а увеличение приведет к автоматической отгрузке
- изменение даты забора пака `pack[shipmentDate]` недоступно

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `pack` | `object (SerializedOrderProductPack)` |     |     |
| `pack[purchasePrice]` | `float` |     | Закупочная цена (в базовой валюте) |
| `pack[quantity]` | `float` |     | Количество товара в упаковке |
| `pack[shipmentDate]` | `DateTime` |     | Дата забора пака |
| `pack[invoiceNumber]` | `string` |     | Номер счет-фактуры |
| `pack[deliveryNoteNumber]` | `string` |     | Номер товарной накладной |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | ID пака |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пак успешно изменён</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении пака</p></li></ul></td></tr></tbody></table>

## Платежи

### POST /api/v5/payment/check

Проверка инвойса

Для доступа к методу необходимо разрешение `payments_read`.

Метод позволяет проверить параметры инвойса перед списанием средств

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `check` | `object (ApiCheckRequest)` |     | JSON с данными для проверки |
| `check[invoiceUuid]` | `string` | `{not blank}` | UUID инвойса в системе |
| `check[amount]` | `float` | `{not blank}` | Сумма |
| `check[currency]` | `string` | `{not blank}` | Код валюты в формате ISO-4217 |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result` | `object (ApiCheckResponseResult)` | Объект с результатом проверки |
| `result[success]` | `boolean` | Результат проверки (успешный/неуспешный) |
| `result[errorMsg]` | `string` | Текст ошибки (в случае, если проверка не прошла) |

### POST /api/v5/payment/create-invoice

Создание инвойса

Для доступа к методу необходимо разрешение `payments_write`.

Метод позволяет создать ссылку на оплату для заданного платежа

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `createInvoice` | `object (ApiCreateInvoiceRequest)` |     | JSON с данными инвойса |
| `createInvoice[paymentId]` | `integer` | `{range: {<=2147483647}}{not blank}}` | Внутренний ID платежа |
| `createInvoice[returnUrl]` | `string` | `{url}` | URL, на который вернется пользователь после подтверждения или отмены платежа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `result` | `object (ApiCreateInvoiceResponseResult)` | JSON с данными созданного инвойса |
| `result[link]` | `string` | Ссылка на страницу оплаты для покупателя |

### POST /api/v5/payment/invoice/import

Импорт инвойса

Для доступа к методу необходимо разрешение `payments_write`.

Метод позволяет импортировать инвойс. Поля `invoice[status]` и `invoice[refunds][][status]` могут быть только в значении `succeeded`. В случае указания `invoice[refunds]` все внутренние поля (кроме `comment`) обязательны к заполнению.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `invoice` | `object (ApiImportInvoiceRequest)` |     | JSON с данными инвойса |
| `invoice[paymentId]` | `integer` | `{not blank}` | Внутренний ID платежа |
| `invoice[externalId]` | `string` | `{not blank}{length: {max: 255}}}` | Внешний идентификатор оплаты в модуле |
| `invoice[amount]` | `float` | `{not blank}` | Сумма |
| `invoice[currency]` | `string` | `{not blank}` | Код валюты в формате ISO-4217 |
| `invoice[status]` | `string` | `{not blank}` | Статус |
| `invoice[createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата и время создания |
| `invoice[paidAt]` | `DateTime` | `Y-m-d H:i:s` | Дата и время платежа |
| `invoice[discountAmount]` | `float` |     | Скидка |
| `invoice[refunds][]` | `array of objects (ApiImportInvoicePaymentRefund)` |     | Данные возвратов |
| `invoice[refunds][][externalId]` | `string` |     | Внешний идентификатор возврата в модуле |
| `invoice[refunds][][status]` | `string` |     | Статус |
| `invoice[refunds][][comment]` | `string` |     | Комментарий |
| `invoice[refunds][][amount]` | `float` |     | Сумма |
| `invoice[refunds][][createdAt]` | `DateTime` | `Y-m-d H:i:s` | Дата и время создания |
| `invoice[refundable]` | `boolean` |     | Возможность сделать возврат |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `invoice` | `object (PaymentInvoice)` |     |
| `invoice[invoiceUuid]` | `string` | UUID инвойса |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Инвойс успешно импортирован</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка импорта инвойса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/payment/invoice/{invoiceUuid}

Получение инвойса

Для доступа к методу необходимо разрешение `payments_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `invoiceUuid` | `string` |     | UUID инвойса |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `invoice` | `object (PaymentInvoice)` |     |
| `invoice[createdAt]` | `DateTime` | Дата и время создания |
| `invoice[customerId]` | `integer` | ID покупателя |
| `invoice[orderId]` | `integer` | ID заказа |
| `invoice[paymentId]` | `integer` | Внутренний ID платежа |
| `invoice[amount]` | `string` | Сумма |
| `invoice[currency]` | `string` | Код валюты в формате ISO-4217 |
| `invoice[email]` | `string` | E-mail |
| `invoice[phone]` | `string` | Телефон |
| `invoice[status]` | `string` | Статус |
| `invoice[statusMessage]` | `string` | Комментарий к статусу |
| `invoice[externalId]` | `string` | Внутренний идентификатор оплаты в модуле |
| `invoice[invoiceUuid]` | `string` | UUID инвойса |
| `invoice[invoiceType]` | `string` | Тип инвойса |
| `invoice[link]` | `string` | Ссылка на страницу оплаты для покупателя |
| `invoice[errorMsg]` | `string` | Ошибка в ответе |
| `invoice[paidAt]` | `DateTime` | Дата и время платежа |
| `invoice[expiredAt]` | `DateTime` | Дата и время, до которого инвойс, находящийся в статусе waitingForCapture будет ожидать подтверждения/отмены |
| `invoice[cancellationDetails]` | `string` | Причина отмены платежа |
| `invoice[refundable]` | `boolean` | Возможность сделать возврат |
| `invoice[cancellable]` | `boolean` | Возможность отменить платёж |
| `invoice[refunds][]` | `array of objects (PaymentRefund)` | Данные возвратов |
| `invoice[refunds][][id]` | `integer` | ID возврата |
| `invoice[refunds][][status]` | `string` | Статус |
| `invoice[refunds][][externalId]` | `string` | Внешний ID |
| `invoice[refunds][][comment]` | `string` | Комментарий |
| `invoice[refunds][][amount]` | `string` | Сумма (в валюте объекта) |
| `invoice[refunds][][createdAt]` | `DateTime` | Дата и время создания |
| `invoice[discountAmount]` | `string` | Скидка (в валюте объекта) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Инвойс не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/payment/update-invoice

Изменение инвойса

Для доступа к методу необходимо разрешение `payments_write`.

Метод позволяет изменить данные инвойса в системе.

При передаче суммы скидки `updateInvoice[discountAmount]` необходимо уменьшать сумму платежа `updateInvoice[amount]` на соответствующую величину. Данная скидка будет добавлена к заказу в системе как `Разовая скидка`.

Изменение суммы платежа `updateInvoice[amount]` с указанием скидки `updateInvoice[discountAmount]` возможно только при смене статуса оплаты `updateInvoice[status]` на `waitingForCapture` или `succeeded`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `updateInvoice` | `object (ApiUpdateInvoiceRequest)` |     | JSON с данными инвойса |
| `updateInvoice[invoiceUuid]` | `string` | `{not blank}` | UUID инвойса в системе |
| `updateInvoice[paymentId]` | `string` | `{length: {max: 255}}` | Внутренний идентификатор оплаты в модуле (UUID) |
| `updateInvoice[amount]` | `float` |     | Сумма платежа |
| `updateInvoice[status]` | `string` | `{length: {max: 50}}` | Код статуса оплаты |
| `updateInvoice[cancellationDetails]` | `string` | `{length: {max: 255}}` | Причина отмены платежа |
| `updateInvoice[invoiceUrl]` | `string` | `{url}` | Ссылка на страницу оплаты для покупателя |
| `updateInvoice[paidAt]` | `string` | `{DateTime YYYY-MM-DD HH:MM:SS}` | Дата и время оплаты |
| `updateInvoice[expiredAt]` | `string` | `{DateTime YYYY-MM-DD HH:MM:SS}` | Дата и время, до которых платеж будет ожидать подтверждения или отмены (при двухстадийной оплате) |
| `updateInvoice[refund]` | `object (ModuleRefund)` |     | JSON с данными возврата |
| `updateInvoice[refund][status]` | `string` |     | Статус возврата. Возможные значения: `pending`, `succeeded`, `canceled` |
| `updateInvoice[refund][id]` | `string` |     | Внутренний идентификатор возврата в модуле |
| `updateInvoice[refund][comment]` | `string` |     | Комментарий |
| `updateInvoice[refund][amount]` | `float` |     | Сумма возврата |
| `updateInvoice[discountAmount]` | `float` |     | Сумма скидки |
| `updateInvoice[refundable]` | `boolean` |     | Признак возможности возврата платежа |
| `updateInvoice[cancellable]` | `boolean` |     | Признак возможности отмены платежа |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["payment"\]\["actions"\]\["approve"\]}

Подтверждение оплаты

Для подтверждения оплаты при холдировании система инициирует POST вызов метода, указанного в integrationModule\["integrations"\]\["payment"\]\["actions"\]\["approve"\] конфигурации

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `approve` | `object (ModuleApiRequest)` |     | JSON с данными запроса |
| `approve[paymentId]` | `string` |     | Внутренний идентификатор оплаты в модуле |
| `approve[amount]` | `float` |     | Итоговая сумма, которая спишется с клиента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `errors` | `array` | Массив с детализациями ошибок |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["payment"\]\["actions"\]\["cancel"\]}

Отмена оплаты

Для отмены оплаты система инициирует POST вызов метода, указанного в integrationModule\["integrations"\]\["payment"\]\["actions"\]\["cancel"\] конфигурации

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `cancel` | `object (ModuleApiRequest)` |     | JSON с данными запроса |
| `cancel[paymentId]` | `string` |     | Внутренний идентификатор оплаты в модуле |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `errors` | `array` | Массив с детализациями ошибок |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["payment"\]\["actions"\]\["create"\]}

Создание оплаты

Для создания оплаты система инициирует POST вызов метода, указанного в integrationModule\["integrations"\]\["payment"\]\["actions"\]\["create"\] конфигурации.  
Для товаров с введенными кодами маркировки, каждая единица товара будет передана отдельной строкой в `create[items][]`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `create` | `object (Create)` |     | JSON с данными для создания оплаты |
| `create[shopId]` | `string` |     | ID магазина |
| `create[invoiceUuid]` | `string` |     | Идентификатор инвойса в системе(UUID). Все обращения к API системы совершаются через этот ID. |
| `create[invoiceType]` | `string` |     | Тип инвойса. Возможные значения: `link` |
| `create[amount]` | `float` |     | Сумма в выбранной валюте |
| `create[currency]` | `string` |     | Код валюты в формате ISO-4217 |
| `create[orderNumber]` | `string` |     | Номер заказа |
| `create[orderId]` | `integer` |     | Внутренний ID заказа |
| `create[siteUrl]` | `string` |     | URL магазина |
| `create[returnUrl]` | `string` |     | URL, на который вернется пользователь после подтверждения или отмены платежа |
| `create[items][]` | `array of objects (Item)` |     | Список товаров |
| `create[items][][name]` | `string` |     | Наименование |
| `create[items][][price]` | `float` |     | Цена |
| `create[items][][quantity]` | `float` |     | Количество |
| `create[items][][measurementUnit]` | `string` |     | Единица измерения |
| `create[items][][vat]` | `string` |     | Ставка НДС. Возможные значения: `none, vat0, vat10, vat110, vat20, vat120` |
| `create[items][][paymentMethod]` | `string` |     | Признак способа расчета. Возможные значения: `full_prepayment, advance` |
| `create[items][][paymentObject]` | `string` |     | Признак предмета расчета. Возможные значения: `commodity, service, payment` |
| `create[items][][productCode]` | `string` |     | Код маркировки в шестнадцатеричном представлении |
| `create[items][][markingCode]` | `string` |     | Код маркировки |
| `create[items][][offerId]` | `integer` |     | ID торгового предложения |
| `create[items][][offerExternalId]` | `string` |     | Внешний ID торгового предложения |
| `create[items][][offerXmlId]` | `string` |     | ID торгового предложения в складской системе |
| `create[customer]` | `object (Customer)` |     | Данные покупателя |
| `create[customer][email]` | `string` |     | Адрес электронной почты |
| `create[customer][phone]` | `string` |     | Номер телефона |
| `create[customer][firstName]` | `string` |     | Имя |
| `create[customer][lastName]` | `string` |     | Фамилия |
| `create[customer][patronymic]` | `string` |     | Отчество |
| `create[customer][sex]` | `string` |     | Пол, возможные значения: `male, female` |
| `create[customer][contragentType]` | `string` |     | Тип контрагента: физ. лицо `individual`, юр. лицо `legal-entity`, ИП `enterpreneur` |
| `create[customer][legalName]` | `string` |     | Полное наименование юр.лица или ИП, передается в случае фискализации на стороне модуля для юр. лиц и ИП |
| `create[customer][INN]` | `string` |     | ИНН клиента, передается в случае фискализации на стороне модуля для юр. лиц и ИП |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `result` | `object (Result)` | JSON с информацией о созданном платеже |
| `result[paymentId]` | `string` | Внутренний идентификатор оплаты в модуле |
| `result[invoiceUrl]` | `string` | Ссылка на страницу оплаты для покупателя |
| `result[cancellable]` | `boolean` | Признак возможности отмены платежа |
| `errors` | `array` | Массив с детализациями ошибок |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["payment"\]\["actions"\]\["refund"\]}

Возврат

Для осуществления возврата система инициирует POST вызов метода, указанного в integrationModule\["integrations"\]\["payment"\]\["actions"\]\["refund"\] конфигурации

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `refund` | `object (ModuleApiRequest)` |     | JSON с данными запроса |
| `refund[paymentId]` | `string` |     | Внутренний идентификатор оплаты в модуле |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `result` | `object (ModuleRefund)` | JSON с данными возврата |
| `result[status]` | `string` | Статус возврата. Возможные значения: `pending`, `succeeded`, `canceled` |
| `result[id]` | `string` | Внутренний идентификатор возврата в модуле |
| `result[comment]` | `string` | Комментарий |
| `result[amount]` | `float` | Сумма возврата |
| `errors` | `array` | Массив с детализациями ошибок |

## Рекомендации

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["recommendation"\]\["actions"\]\["recommendation"\]}

Запрос рекомендуемых товаров

Для получения списка рекомендаций система инициирует GET запрос метода указанного в `integrationModule[integrations][recommendation]["actions"]["recommendation"]`. Система передает идентификаторы товара и тип запрашиваемых рекомендаций.

В ответе система ожидает получить список идентификаторов товаров и название поля по которому происходит идентификация.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     | Идентификатор клиента во внешнем сервисе |
| `ids[]` | `array of integers` |     | ID товаров |
| `externalIds[]` | `array of strings` |     | externalId товаров |
| `mode` | `string` |     | Тип рекомендаций: товары из серии «Покупают с» или товары из серии «Аналоги». Возможные значения `buying_with`, `analogs` |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `by` | `string` | Название поля для идентификации товаров. Возможные значения `id`, `externalId` |
| `ids[]` | `array of strings` | Массив с идентификаторами товаров |

## Справочники

### GET /api/v5/reference/cost-groups

Получение списка групп расходов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `costGroups[]` | `array of objects (CostGroup)` | Группа расходов |
| `costGroups[][code]` | `string` | Символьный код группы расходов |
| `costGroups[][name]` | `string` | Название группы расходов |
| `costGroups[][ordering]` | `integer` | Порядок |
| `costGroups[][active]` | `boolean` | Активность |
| `costGroups[][color]` | `string` | Цвет |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/cost-groups/{code}/edit

Редактирование группы расходов

Для доступа к методу необходимо разрешение `reference_write`.

Для новой группы расходов необходимо указать название `name`, символьный код `code` и цвет `color`.

Символьный код должен быть уникальным. Цвет должен быть указан в формате `#RRGGBB`, пример `#123ABC`.

Список доступных цветов:

- `#ffd298`
- `#ef8e06`
- `#bc6b01`
- `#19976e`
- `#8453df`
- `#ff8e87`
- `#c7cdd4`
- `#4191ff`
- `#8a96a6`
- `#6ce0b9`

С помощью данного метода нельзя изменить цвет `color` или символьный код `code` системных групп расходов, значение `system` которых равно `true`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `costGroup` | `object (SerializedCostGroup)` |     |     |
| `costGroup[code]` | `string` | `{not blank}{length: {max: 255}}}` | Символьный код группы расходов |
| `costGroup[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название группы расходов |
| `costGroup[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Порядок |
| `costGroup[active]` | `boolean` |     | Активность |
| `costGroup[color]` | `string` | `{not blank}{length: {max: 7}}{match: /#[0-9,a-f,A-F]{6}/}[#19976e\|#4191ff\|#6ce0b9\|#8453df\|#8a96a6\|#bc6b01\|#c7cdd4\|#ef8e06\|#ff8e87\|#ffd298]}` | Цвет |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные группы расходов успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новая группа расходов успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/cost-items

Получение списка статей расходов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `costItems[]` | `array of objects (CostItem)` | Статья расходов |
| `costItems[][source]` | `object (SerializedSource)` | Данные по источнику клиента |
| `costItems[][source][source]` | `string` | Источник |
| `costItems[][source][medium]` | `string` | Канал |
| `costItems[][source][campaign]` | `string` | Кампания |
| `costItems[][source][keyword]` | `string` | Ключевое слово |
| `costItems[][source][content]` | `string` | Содержание кампании |
| `costItems[][code]` | `string` | Символьный код статьи расходов |
| `costItems[][name]` | `string` | Название статьи расходов |
| `costItems[][group]` | `string` | Символьный код группы расходов |
| `costItems[][ordering]` | `integer` | Порядок |
| `costItems[][active]` | `boolean` | Активность |
| `costItems[][appliesToOrders]` | `boolean` | Относится к расходам по заказам |
| `costItems[][type]` | `string` | Тип расхода |
| `costItems[][appliesToUsers]` | `boolean` | Относится к расходам по пользователям |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/cost-items/{code}/edit

Редактирование статьи расходов

Для доступа к методу необходимо разрешение `reference_write`.

При создании или редактировании статьи расхода, принадлежащей группе `Расходы на привлечение клиентов` в полях `costItem[source][...]` можно указать значения соответствующих меток. Данные метки будут по умолчанию установлены для всех расходов, принадлежащих данной статье.

Для указания типа статьи расхода доступны следующие значения:

- `const` - постоянный расход
- `var` - переменный расход

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `costItem` | `object (SerializedCostItem)` |     |     |
| `costItem[code]` | `string` |     | Символьный код статьи расходов |
| `costItem[name]` | `string` |     | Название статьи расходов |
| `costItem[ordering]` | `integer` |     | Порядок |
| `costItem[active]` | `boolean` |     | Активность |
| `costItem[appliesToOrders]` | `boolean` |     | Относится к расходам по заказам |
| `costItem[appliesToUsers]` | `boolean` |     | Относится к расходам по пользователям |
| `costItem[group]` | `string` |     | Символьный код группы расходов |
| `costItem[source]` | `object (SerializedSource)` |     | Данные по источнику клиента |
| `costItem[source][source]` | `string` |     | Источник |
| `costItem[source][medium]` | `string` |     | Канал |
| `costItem[source][campaign]` | `string` |     | Кампания |
| `costItem[source][keyword]` | `string` |     | Ключевое слово |
| `costItem[source][content]` | `string` |     | Содержание кампании |
| `costItem[type]` | `string` |     | Тип расхода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные статьи расходов успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новая статья расходов успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/countries

Получение списка кодов доступных стран

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `countriesIso[]` | `array of strings` | Список ISO 3166-1 alpha-2 кодов активных стран |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/couriers

Получение списка курьеров

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `couriers[]` | `array of objects (Courier)` | Курьер |
| `couriers[][id]` | `integer` | ID курьера |
| `couriers[][firstName]` | `string` | Имя |
| `couriers[][lastName]` | `string` | Фамилия |
| `couriers[][patronymic]` | `string` | Отчество |
| `couriers[][active]` | `boolean` | Признак активности |
| `couriers[][email]` | `string` | Электронная почта |
| `couriers[][phone]` | `object (CourierPhone)` | Контактный телефон |
| `couriers[][phone][number]` | `string` | Номер телефона |
| `couriers[][description]` | `string` | Примечание |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/couriers/create

Создание курьера

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `courier` | `object (SerializedCourier)` |     |     |
| `courier[firstName]` | `string` |     | Имя |
| `courier[lastName]` | `string` |     | Фамилия |
| `courier[patronymic]` | `string` |     | Отчество |
| `courier[active]` | `boolean` |     | Признак активности |
| `courier[email]` | `string` |     | Электронная почта |
| `courier[phone]` | `object (CourierPhone)` |     | Контактный телефон |
| `courier[phone][number]` | `string` |     | Номер телефона |
| `courier[description]` | `string` |     | Примечание |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного объекта |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый курьер успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/couriers/{id}/edit

Редактирование курьера

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `courier` | `object (SerializedCourier)` |     |     |
| `courier[firstName]` | `string` |     | Имя |
| `courier[lastName]` | `string` |     | Фамилия |
| `courier[patronymic]` | `string` |     | Отчество |
| `courier[active]` | `boolean` |     | Признак активности |
| `courier[email]` | `string` |     | Электронная почта |
| `courier[phone]` | `object (CourierPhone)` |     | Контактный телефон |
| `courier[phone][number]` | `string` |     | Номер телефона |
| `courier[description]` | `string` |     | Примечание |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные курьера успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Курьер не найден</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/currencies

Получение списка валют

Для доступа к методу необходимо разрешение `reference_read`.

Базовая валюта имеет признак `currencies[][isBase]=true`. Все прочие возвращаемые поля относятся к настройкам дополнительных валют.

Если дополнительная валюта в режиме автоматической конвертации `currencies[][isAutoConvert]=true`, также возвращается значение наценки `currencies[][autoConvertExtraPercent]`.

Если дополнительная валюта в режиме ручной конвертации `currencies[][isAutoConvert]=false`, также возвращаются значения номинала валюты `currencies[][manualConvertNominal]` и курса конвертации `currencies[][manualConvertValue]`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `currencies[]` | `array of objects (Currency)` | Валюта |
| `currencies[][id]` | `integer` | ID  |
| `currencies[][code]` | `string` | Код валюты |
| `currencies[][isBase]` | `boolean` | Является базовой валютой |
| `currencies[][isAutoConvert]` | `boolean` | Автоматическая конвертация валюты |
| `currencies[][autoConvertExtraPercent]` | `integer` | Наценка в % при автоматической конвертации |
| `currencies[][manualConvertNominal]` | `integer` | Номинал валюты при ручной конвертации |
| `currencies[][manualConvertValue]` | `float` | Курс валюты при ручной конвертации |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/currencies/create

Создание валюты

Для доступа к методу необходимо разрешение `reference_write`.

Создавать можно только дополнительные валюты.

В поле `currency[isAutoConvert]` указывает режим конвертации (`true` если автоматический и `false` если ручной).

Автоматический курс разрешено устанавливать, если базовая валюта относится к одной из следующих валют — Белорусский рубль, Казахский тенге, Российский рубль, и Центральным банком соответствующей базовой валюты формируется курс целевой валюты.

При автоматическом режиме конвертации также можно указать величину наценки `currency[autoConvertExtraPercent]`. При ручном режиме конвертации можно указать номинал валюты `currency[manualConvertNominal]` и курс конвертации `currency[manualConvertValue]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `currency` | `object (SerializedCurrency)` |     | Валюта |
| `currency[code]` | `string` | `{not blank}{length: {min: 3, max: 3}}}` | Код валюты |
| `currency[autoConvertExtraPercent]` | `integer` | `{range: {>=0, <=100}}` | Наценка в % при автоматической конвертации |
| `currency[manualConvertNominal]` | `integer` | `[1\|10\|100\|1000\|10000]` | Номинал валюты при ручной конвертации |
| `currency[manualConvertValue]` | `float` |     | Курс валюты при ручной конвертации |
| `currency[isAutoConvert]` | `boolean` |     | Автоматическая конвертация валюты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного объекта |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новая валюта успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/currencies/{id}/edit

Редактирование валюты

Для доступа к методу необходимо разрешение `reference_write`.

Редактировать можно как базовую, так и дополнительные валюты, но отличается состав полей, который принимается к редактированию. При редактировании базовой валюты можно изменять только саму валюту `currency[code]`. При редактировании дополнительных валют можно изменять все прочие поля, кроме `currency[code]`.

В поле `currency[isAutoConvert]` указывает режим конвертации (`true` если автоматический и `false` если ручной).

Автоматический курс разрешено устанавливать, если базовая валюта относится к одной из следующих валют — Белорусский рубль, Казахский тенге, Российский рубль, и Центральным банком соответствующей базовой валюты формируется курс целевой валюты.

При автоматическом режиме конвертации также можно указать величину наценки `currency[autoConvertExtraPercent]`. При ручном режиме конвертации можно указать номинал валюты `currency[manualConvertNominal]` и курс конвертации `currency[manualConvertValue]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `currency` | `object (SerializedCurrency)` |     | Валюта |
| `currency[code]` | `string` | `{not blank}{length: {min: 3, max: 3}}}` | Код валюты |
| `currency[autoConvertExtraPercent]` | `integer` | `{range: {>=0, <=100}}` | Наценка в % при автоматической конвертации |
| `currency[manualConvertNominal]` | `integer` | `[1\|10\|100\|1000\|10000]` | Номинал валюты при ручной конвертации |
| `currency[manualConvertValue]` | `float` |     | Курс валюты при ручной конвертации |
| `currency[isAutoConvert]` | `boolean` |     | Автоматическая конвертация валюты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Валюта успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Валюта не найдена</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/delivery-services

Получение списка служб доставки

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `deliveryServices[]` | `array of objects (DeliveryService)` | Служба доставки |
| `deliveryServices[][name]` | `string` | Название |
| `deliveryServices[][code]` | `string` | Символьный код |
| `deliveryServices[][active]` | `boolean` | Статус активности |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/delivery-services/{code}/edit

Создание/редактирование службы доставки

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `deliveryService` | `object (SerializedDeliveryService)` |     |     |
| `deliveryService[code]` | `string` | `{not blank}` | Символьный код |
| `deliveryService[name]` | `string` | `{not blank}` | Название |
| `deliveryService[deliveryType]` | `string` |     | Тип доставки |
| `deliveryService[active]` | `boolean` |     | Статус активности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Служба доставки успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новая служба доставки успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/delivery-types

Получение списка типов доставки

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `deliveryTypes[]` | `array of objects (DeliveryType)` | Тип доставки |
| `deliveryTypes[][paymentTypes]` | `array` | `deprecated` Разрешенные типы оплат. Используйте `deliveryPaymentTypes` |
| `deliveryTypes[][isDynamicCostCalculation]` | `boolean` | Динамический тип расчета стоимости доставки |
| `deliveryTypes[][isAutoCostCalculation]` | `boolean` | Стоимости доставки расчитывается автоматически службой доставки |
| `deliveryTypes[][isAutoNetCostCalculation]` | `boolean` | Себестоимости доставки расчитывается автоматически службой доставки |
| `deliveryTypes[][isCostDependsOnRegionAndWeightAndSum]` | `boolean` | Стоимость доставки зависит от региона, веса и суммы заказа |
| `deliveryTypes[][isCostDependsOnDateTime]` | `boolean` | Стоимость доставки зависит от времени и дня недели |
| `deliveryTypes[][currency]` | `string` | Валюта |
| `deliveryTypes[][name]` | `string` | Название |
| `deliveryTypes[][code]` | `string` | Символьный код |
| `deliveryTypes[][active]` | `boolean` | Статус активности |
| `deliveryTypes[][defaultCost]` | `double` | Стоимость по умолчанию (в валюте объекта) |
| `deliveryTypes[][defaultNetCost]` | `double` | Себестоимость по умолчанию (в валюте объекта) |
| `deliveryTypes[][description]` | `string` | Комментарий |
| `deliveryTypes[][deliveryPaymentTypes][]` | `array of objects (DeliveryTypePaymentType)` | Разрешенные типы оплат |
| `deliveryTypes[][deliveryPaymentTypes][][code]` | `string` | Символьный код |
| `deliveryTypes[][deliveryPaymentTypes][][cod]` | `boolean` | Оплата наложенным платежом |
| `deliveryTypes[][integrationCode]` | `string` | Код интеграционного модуля |
| `deliveryTypes[][deliveryServices]` | `array` | Службы доставок, которые входят в данный тип доставки |
| `deliveryTypes[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `deliveryTypes[][vatRate]` | `string` | Ставка НДС |
| `deliveryTypes[][defaultTariffCode]` | `string` | Код тарифа по умолчанию |
| `deliveryTypes[][defaultTariffType]` | `string` | Тип тарифа по умолчанию |
| `deliveryTypes[][defaultTariffName]` | `string` | Название тарифа по умолчанию |
| `deliveryTypes[][sites]` | `array` | Магазины, в которых доступен данный тип доставки. Если пустой массив, то доступен во всех |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/delivery-types/{code}/edit

Создание/редактирование типа доставки

Для доступа к методу необходимо разрешение `reference_write`.

Для нового типа доставки необходимо указать название `name` и символьный код `code`. Символьный код должен быть уникальным.

В поле `integrationCode` можно указывать код только той интеграционной службы доставки, которая активирована в системе. В противном случае будет выдано сообщение об ошибке.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `deliveryType` | `object (SerializedDeliveryType)` |     |     |
| `deliveryType[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `deliveryType[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `deliveryType[defaultCost]` | `double` | `{not blank}{range: {>=0}}}` | Стоимость по умолчанию (в валюте объекта) |
| `deliveryType[defaultNetCost]` | `double` | `{not blank}{range: {>=0}}}` | Себестоимость по умолчанию (в валюте объекта) |
| `deliveryType[sites]` | `array` |     | Массив символьных кодов магазинов, в которых доступен тип доставки |
| `deliveryType[integrationModule]` | `string` |     |     |
| `deliveryType[regionWeightCostConditions]` | `string` |     |     |
| `deliveryType[vatRate]` | `string` |     | Ставка НДС |
| `deliveryType[defaultTariffCode]` | `string` |     | Код тарифа по умолчанию |
| `deliveryType[defaultTariffType]` | `string` |     | Тип тарифа по умолчанию |
| `deliveryType[defaultTariffName]` | `string` |     | Название тарифа по умолчанию |
| `deliveryType[paymentTypes]` | `array` |     | `deprecated` Разрешенные типы оплат. Используйте `deliveryPaymentTypes` |
| `deliveryType[active]` | `boolean` |     | Статус активности |
| `deliveryType[description]` | `string` |     | Комментарий |
| `deliveryType[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `deliveryType[integrationCode]` | `string` |     | Код интеграционного модуля |
| `deliveryType[deliveryServices]` | `array` |     | Службы доставок, которые входят в данный тип доставки |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Тип доставки успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый тип доставки успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/legal-entities

Получение списка юридических лиц

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `legalEntities[]` | `array of objects (LegalEntity)` | Список юридических лиц |
| `legalEntities[][contragentType]` | `string` | Тип юридического лица |
| `legalEntities[][legalName]` | `string` | Полное наименование |
| `legalEntities[][legalAddress]` | `string` | Адрес регистрации |
| `legalEntities[][INN]` | `string` | ИНН |
| `legalEntities[][OKPO]` | `string` | ОКПО |
| `legalEntities[][KPP]` | `string` | КПП |
| `legalEntities[][OGRN]` | `string` | ОГРН |
| `legalEntities[][OGRNIP]` | `string` | ОГРНИП |
| `legalEntities[][certificateNumber]` | `string` | Номер свидетельства |
| `legalEntities[][certificateDate]` | `DateTime` | Дата свидетельства |
| `legalEntities[][BIK]` | `string` | БИК |
| `legalEntities[][bank]` | `string` | Банк |
| `legalEntities[][bankAddress]` | `string` | Адрес банка |
| `legalEntities[][corrAccount]` | `string` | Корр. счёт |
| `legalEntities[][bankAccount]` | `string` | Расчётный счёт |
| `legalEntities[][code]` | `string` | Символьный код |
| `legalEntities[][countryIso]` | `string` | Страна |
| `legalEntities[][vatRate]` | `string` | Ставка НДС |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/legal-entities/{code}/edit

Создание/редактирование юридического лица

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `legalEntity` | `object (SerializedLegalEntity)` |     |     |
| `legalEntity[contragentType]` | `string` |     | Тип юридического лица |
| `legalEntity[legalName]` | `string` |     | Полное наименование |
| `legalEntity[legalAddress]` | `string` |     | Адрес регистрации |
| `legalEntity[INN]` | `string` |     | ИНН |
| `legalEntity[OKPO]` | `string` |     | ОКПО |
| `legalEntity[KPP]` | `string` |     | КПП |
| `legalEntity[OGRN]` | `string` |     | ОГРН |
| `legalEntity[OGRNIP]` | `string` |     | ОГРНИП |
| `legalEntity[certificateNumber]` | `string` |     | Номер свидетельства |
| `legalEntity[certificateDate]` | `DateTime` |     | Дата свидетельства |
| `legalEntity[BIK]` | `string` |     | БИК |
| `legalEntity[bank]` | `string` |     | Банк |
| `legalEntity[bankAddress]` | `string` |     | Адрес банка |
| `legalEntity[corrAccount]` | `string` |     | Корр. счёт |
| `legalEntity[bankAccount]` | `string` |     | Расчётный счёт |
| `legalEntity[code]` | `string` |     | Символьный код |
| `legalEntity[countryIso]` | `string` |     | Страна |
| `legalEntity[vatRate]` | `string` |     | Ставка НДС |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные юридического лица успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новое юридическое лицо успешно создано</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/mg-channels

Получение списка MessageGateway каналов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `mgChannels[]` | `array of objects (MGChannel)` | MessageGateway канал |
| `mgChannels[][allowedSendByPhone]` | `custom handler result for (bool)` | Можно ли писать первыми в этот канал по номеру телефона |
| `mgChannels[][id]` | `integer` | ID канала |
| `mgChannels[][externalId]` | `integer` | Внешний ID канала |
| `mgChannels[][type]` | `string` | Тип канала |
| `mgChannels[][active]` | `boolean` | Активность канала |
| `mgChannels[][name]` | `string` | Название канала |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/order-methods

Получение списка способов оформления заказов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `orderMethods[]` | `array of objects (OrderMethod)` | Способ оформления заказа |
| `orderMethods[][name]` | `string` | Название |
| `orderMethods[][code]` | `string` | Символьный код |
| `orderMethods[][active]` | `boolean` | Статус активности |
| `orderMethods[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `orderMethods[][defaultForApi]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых через API |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/order-methods/{code}/edit

Создание/редактирование способа оформления заказа

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `orderMethod` | `object (SerializedOrderMethod)` |     |     |
| `orderMethod[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `orderMethod[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `orderMethod[active]` | `boolean` |     | Статус активности |
| `orderMethod[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `orderMethod[defaultForApi]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых через API |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Способ оформления заказа успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый способ оформления заказа успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/order-types

Получение списка типов заказов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `orderTypes[]` | `array of objects (OrderType)` | Тип заказа |
| `orderTypes[][name]` | `string` | Название |
| `orderTypes[][code]` | `string` | Символьный код |
| `orderTypes[][active]` | `boolean` | Статус активности |
| `orderTypes[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `orderTypes[][defaultForApi]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых через API |
| `orderTypes[][ordering]` | `integer` | Порядок |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/order-types/{code}/edit

Создание/редактирование типа заказа

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `orderType` | `object (SerializedOrderType)` |     |     |
| `orderType[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `orderType[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `orderType[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Порядок |
| `orderType[active]` | `boolean` |     | Статус активности |
| `orderType[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `orderType[defaultForApi]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых через API |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Тип заказа успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый тип заказа успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/payment-statuses

Получение списка статусов оплаты

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `paymentStatuses[]` | `array of objects (PaymentStatus)` | Статус оплаты |
| `paymentStatuses[][name]` | `string` | Название |
| `paymentStatuses[][code]` | `string` | Символьный код |
| `paymentStatuses[][active]` | `boolean` | Статус активности |
| `paymentStatuses[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `paymentStatuses[][defaultForApi]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых через API |
| `paymentStatuses[][paymentComplete]` | `boolean` | Признак того, что заказ оплачен |
| `paymentStatuses[][ordering]` | `integer` | Порядок |
| `paymentStatuses[][description]` | `string` | Комментарий |
| `paymentStatuses[][paymentTypes]` | `array` | Типы оплаты, где используется данный статус оплаты |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/payment-statuses/{code}/edit

Создание/редактирование статусов оплаты

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `paymentStatus` | `object (SerializedPaymentStatus)` |     |     |
| `paymentStatus[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `paymentStatus[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `paymentStatus[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Порядок |
| `paymentStatus[active]` | `boolean` |     | Статус активности |
| `paymentStatus[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `paymentStatus[defaultForApi]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых через API |
| `paymentStatus[paymentComplete]` | `boolean` |     | Признак того, что заказ оплачен |
| `paymentStatus[description]` | `string` |     | Комментарий |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Статус оплаты успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый статус оплаты успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/payment-types

Получение списка типов оплаты

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `paymentTypes[]` | `array of objects (PaymentType)` | Тип оплаты |
| `paymentTypes[][name]` | `string` | Название |
| `paymentTypes[][code]` | `string` | Символьный код |
| `paymentTypes[][active]` | `boolean` | Статус активности |
| `paymentTypes[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `paymentTypes[][defaultForApi]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых через API |
| `paymentTypes[][description]` | `string` | Комментарий |
| `paymentTypes[][deliveryTypes]` | `array` | Совместимые типы доставки |
| `paymentTypes[][paymentStatuses]` | `array` | Массив идентификаторов совместимых статусов оплаты |
| `paymentTypes[][integrationModule]` | `object (IntegrationModule)` | Интеграционный модуль |
| `paymentTypes[][integrationModule][active]` | `boolean` | Статус активности |
| `paymentTypes[][integrationModule][name]` | `string` | Название (требуется, если модуль не опубликован в маркетплейсе) |
| `paymentTypes[][integrationModule][logo]` | `string` | Ссылка на svg логотип (требуется, если модуль не опубликован в маркетплейсе) |
| `paymentTypes[][sites]` | `array` | Магазины, в которых доступен данный тип оплаты. Если пустой массив, то доступен во всех |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/payment-types/{code}/edit

Создание/редактирование типа оплаты

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `paymentType` | `object (SerializedPaymentType)` |     |     |
| `paymentType[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `paymentType[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `paymentType[sites]` | `array` |     | Массив символьных кодов магазинов, в которых доступен тип оплаты |
| `paymentType[active]` | `boolean` |     | Статус активности |
| `paymentType[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `paymentType[defaultForApi]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых через API |
| `paymentType[description]` | `string` |     | Комментарий |
| `paymentType[paymentStatuses]` | `array` |     | Массив идентификаторов совместимых статусов оплаты |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Тип оплаты успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый тип оплаты успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/price-types

Получение списка типов цен

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `priceTypes[]` | `array of objects (PriceType)` | Тип цены |
| `priceTypes[][id]` | `integer` | ID типа цены |
| `priceTypes[][code]` | `string` | Символьный код |
| `priceTypes[][name]` | `string` | Название |
| `priceTypes[][active]` | `boolean` | Активность |
| `priceTypes[][promo]` | `boolean` | Акционная цена |
| `priceTypes[][default]` | `boolean` | Флаг базового типа цен |
| `priceTypes[][description]` | `string` | Описание |
| `priceTypes[][filterExpression]` | `string` | Фильтр |
| `priceTypes[][geo][]` | `array of objects (GeoHierarchyRow)` | Региональные ограничения |
| `priceTypes[][geo][][country]` | `string` | Код страны ISO |
| `priceTypes[][geo][][regionId]` | `string` | Идентификатор региона в Geohelper |
| `priceTypes[][geo][][region]` | `string` | Наименование региона |
| `priceTypes[][geo][][cityId]` | `string` | Идентификатор города в Geohelper |
| `priceTypes[][geo][][city]` | `string` | Наименование города |
| `priceTypes[][groups]` | `array` | Группы пользователей |
| `priceTypes[][ordering]` | `integer` | Порядок |
| `priceTypes[][currency]` | `string` | Валюта |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/price-types/{code}/edit

Создание/редактирование типа цены

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `priceType` | `object (SerializedPriceType)` |     |     |
| `priceType[code]` | `string` |     | Символьный код |
| `priceType[name]` | `string` |     | Название |
| `priceType[active]` | `boolean` |     | Активность |
| `priceType[promo]` | `boolean` |     | Акционная цена |
| `priceType[description]` | `string` |     | Описание |
| `priceType[filterExpression]` | `string` |     | Фильтр |
| `priceType[ordering]` | `integer` |     | Порядок |
| `priceType[geo][]` | `array of objects (GeoHierarchyRow)` |     | Региональные ограничения |
| `priceType[geo][][country]` | `string` |     | Код страны ISO |
| `priceType[geo][][regionId]` | `string` |     | Идентификатор региона в Geohelper |
| `priceType[geo][][cityId]` | `string` |     | Идентификатор города в Geohelper |
| `priceType[groups]` | `array` |     | Группы пользователей |
| `priceType[currency]` | `string` |     | Валюта |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные склада успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый склад успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/product-statuses

Получение списка статусов товаров в заказе

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `productStatuses[]` | `array of objects (OrderProductStatus)` | Статус товара в заказе |
| `productStatuses[][code]` | `string` | Символьный код |
| `productStatuses[][ordering]` | `integer` | Порядок |
| `productStatuses[][active]` | `boolean` | Статус активности |
| `productStatuses[][createdAt]` | `DateTime` | Дата создания |
| `productStatuses[][orderStatusByProductStatus]` | `string` | Статус заказа, который выставляется, если у всех товаров данный статус товара |
| `productStatuses[][orderStatusForProductStatus]` | `string` | Статус заказа, при котором статус товаров меняется на данный статус товара |
| `productStatuses[][cancelStatus]` | `boolean` | Является статусом отмены |
| `productStatuses[][name]` | `string` | Название |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/product-statuses/{code}/edit

Создание/редактирование статуса товара в заказе

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `productStatus` | `object (SerializedOrderProductStatus)` |     |     |
| `productStatus[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `productStatus[code]` | `string` |     | Символьный код |
| `productStatus[type]` | `choice` | `[product-status-type-cancel]` | Тип |
| `productStatus[ordering]` | `integer` |     | Порядок |
| `productStatus[active]` | `boolean` |     | Статус активности |
| `productStatus[cancelStatus]` | `boolean` |     | Является статусом отмены |
| `productStatus[orderStatusByProductStatus]` | `string` |     | Статус заказа, который выставляется, если у всех товаров данный статус товара |
| `productStatus[orderStatusForProductStatus]` | `string` |     | Статус заказа, при котором статус товаров меняется на данный статус товара |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Статус товара успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый статус товара успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/sites

Получение списка магазинов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `sites[]` | `array of objects (Site)` | Магазин |
| `sites[][catalogId]` | `string` | ID каталога |
| `sites[][isCatalogMainSite]` | `boolean` | Основной магазин каталога |
| `sites[][isDemo]` | `boolean` | Магазин с демо данными |
| `sites[][id]` | `integer` | ID  |
| `sites[][name]` | `string` | Название |
| `sites[][url]` | `string` | URL магазина |
| `sites[][code]` | `string` | Символьный код магазина |
| `sites[][description]` | `string` | Комментарий |
| `sites[][phones]` | `string` | Телефоны магазина |
| `sites[][address]` | `string` | Адрес магазина |
| `sites[][zip]` | `string` | Почтовый индекс |
| `sites[][defaultForCrm]` | `boolean` | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `sites[][ymlUrl]` | `string` | Адрес расположения YML |
| `sites[][loadFromYml]` | `boolean` | Загружать ли каталог данного магазина из YML/ICML или нет |
| `sites[][catalogUpdatedAt]` | `DateTime` | Дата/время последней успешной загрузки YML/ICML |
| `sites[][catalogLoadingAt]` | `DateTime` | Дата/время последней загрузки YML/ICML |
| `sites[][ordering]` | `integer` | Порядок |
| `sites[][contragent]` | `object (LegalEntity)` | Юридическое лицо |
| `sites[][contragent][contragentType]` | `string` | Тип юридического лица |
| `sites[][contragent][legalName]` | `string` | Полное наименование |
| `sites[][contragent][legalAddress]` | `string` | Адрес регистрации |
| `sites[][contragent][INN]` | `string` | ИНН |
| `sites[][contragent][OKPO]` | `string` | ОКПО |
| `sites[][contragent][KPP]` | `string` | КПП |
| `sites[][contragent][OGRN]` | `string` | ОГРН |
| `sites[][contragent][OGRNIP]` | `string` | ОГРНИП |
| `sites[][contragent][certificateNumber]` | `string` | Номер свидетельства |
| `sites[][contragent][certificateDate]` | `DateTime` | Дата свидетельства |
| `sites[][contragent][BIK]` | `string` | БИК |
| `sites[][contragent][bank]` | `string` | Банк |
| `sites[][contragent][bankAddress]` | `string` | Адрес банка |
| `sites[][contragent][corrAccount]` | `string` | Корр. счёт |
| `sites[][contragent][bankAccount]` | `string` | Расчётный счёт |
| `sites[][contragent][code]` | `string` | Символьный код |
| `sites[][contragent][countryIso]` | `string` | Страна |
| `sites[][contragent][vatRate]` | `string` | Ставка НДС |
| `sites[][countryIso]` | `string` | ISO код страны |
| `sites[][currency]` | `string` | Валюта |
| `sites[][senderEmail]` | `string` | Адрес отправителя для магазина |
| `sites[][senderName]` | `string` | Имя отправителя |
| `sites[][usedInSimlaweb]` | `boolean` | Связан ли магазин с Simlaweb |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/sites/{code}/edit

Создание/редактирование магазина

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `object (SerializedSite)` |     |     |
| `site[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `site[url]` | `string` | `{url}{length: {max: 255}}}` | URL магазина |
| `site[code]` | `string` | `{length: {max: 255}}` | Символьный код магазина |
| `site[description]` | `string` | `{length: {max: 3000}}` | Комментарий |
| `site[phones]` | `string` | `{length: {max: 255}}` | Телефоны магазина |
| `site[address]` | `string` | `{length: {max: 255}}` | Адрес магазина |
| `site[zip]` | `string` | `{length: {max: 255}}` | Почтовый индекс |
| `site[ymlUrl]` | `string` | `{length: {max: 1000}}` | Адрес расположения YML |
| `site[defaultForCrm]` | `boolean` |     | Устанавливается по умолчанию для заказов, создаваемых в системе |
| `site[loadFromYml]` | `boolean` |     | Загружать ли каталог данного магазина из YML/ICML или нет |
| `site[countryIso]` | `string` |     | ISO код страны |
| `site[contragentCode]` | `string` |     | Символьный код контрагента |
| `site[currency]` | `string` |     | Валюта |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного объекта |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Магазин успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый магазин успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/status-groups

Получение списка групп статусов заказа

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `statusGroups[]` | `array of objects (StatusGroup)` | Группа статусов |
| `statusGroups[][name]` | `string` | Название |
| `statusGroups[][code]` | `string` | Символьный код |
| `statusGroups[][active]` | `boolean` | Статус активности |
| `statusGroups[][ordering]` | `integer` | Порядок |
| `statusGroups[][process]` | `boolean` | Является или нет процессным состоянием заказа |
| `statusGroups[][statuses]` | `array` | Статусы заказов, которые входят в данную группу |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/statuses

Получение списка статусов заказа

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `statuses[]` | `array of objects (Status)` | Статус заказа |
| `statuses[][name]` | `string` | Название |
| `statuses[][code]` | `string` | Символьный код |
| `statuses[][active]` | `boolean` | Статус активности |
| `statuses[][ordering]` | `integer` | Порядок |
| `statuses[][group]` | `string` | Группа статусов, к которой относится статус |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/statuses/{code}/edit

Создание/редактирование статуса заказа

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `status` | `object (SerializedStatus)` |     |     |
| `status[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `status[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `status[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Порядок |
| `status[group]` | `string` | `{not blank}` | Группа статусов, к которой относится статус |
| `status[active]` | `boolean` |     | Статус активности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Статус заказа успешно изменен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый статус заказа успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/stores

Получение списка складов

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `stores[]` | `array of objects (Store)` | Склад |
| `stores[][externalId]` | `string` | Внешний ID |
| `stores[][xmlId]` | `string` | Идентификатор 1С |
| `stores[][description]` | `string` | Описание склада |
| `stores[][email]` | `string` | Email склада |
| `stores[][type]` | `string` | Тип склада |
| `stores[][inventoryType]` | `string` | Вид остатков на складе |
| `stores[][address]` | `object (StoreAddress)` | Адрес склада |
| `stores[][address][index]` | `string` | Индекс |
| `stores[][address][countryIso]` | `string` | ISO код страны |
| `stores[][address][region]` | `string` | Регион |
| `stores[][address][regionId]` | `integer` | Идентификатор региона в Geohelper |
| `stores[][address][city]` | `string` | Город |
| `stores[][address][cityId]` | `integer` | Идентификатор города в Geohelper |
| `stores[][address][cityType]` | `string` | Тип населенного пункта |
| `stores[][address][street]` | `string` | Улица |
| `stores[][address][streetId]` | `integer` | Идентификатор улицы в Geohelper |
| `stores[][address][streetType]` | `string` | Тип улицы |
| `stores[][address][building]` | `string` | Дом |
| `stores[][address][flat]` | `string` | Номер квартиры/офиса |
| `stores[][address][floor]` | `integer` | Этаж |
| `stores[][address][block]` | `integer` | Подъезд |
| `stores[][address][house]` | `string` | Строение |
| `stores[][address][housing]` | `string` | Корпус |
| `stores[][address][metro]` | `string` | Метро |
| `stores[][address][notes]` | `string` | Примечания к адресу |
| `stores[][address][text]` | `string` | Адрес в текстовом виде |
| `stores[][address][coordinates]` | `object (Point)` | Координаты точки |
| `stores[][address][coordinates][latitude]` | `float` | Широта |
| `stores[][address][coordinates][longitude]` | `float` | Долгота |
| `stores[][active]` | `boolean` | Статус активности |
| `stores[][ordering]` | `string` | Порядок |
| `stores[][phone]` | `object (StorePhone)` | Телефон склада |
| `stores[][phone][number]` | `string` | Номер телефона |
| `stores[][contact]` | `string` | Контактное лицо на складе |
| `stores[][code]` | `string` | Символьный код |
| `stores[][workTime]` | `object (SerializedStoreWeekOpeningHours)` | Время работы склада |
| `stores[][workTime][mo][]` | `array of objects (StoreWorkTime)` | Время работы склада в понедельник |
| `stores[][workTime][mo][][startTime]` | `string` | Время начала работы склада (в формате `H:i`) |
| `stores[][workTime][mo][][endTime]` | `string` | Время окончания работы склада (в формате `H:i`) |
| `stores[][workTime][mo][][lunchStartTime]` | `string` | Время начала перерыва (в формате `H:i`) |
| `stores[][workTime][mo][][lunchEndTime]` | `string` | Время окончания перерыва (в формате `H:i`) |
| `stores[][workTime][tu][]` | `array of objects (StoreWorkTime)` | Время работы склада во вторник |
| `stores[][workTime][we][]` | `array of objects (StoreWorkTime)` | Время работы склада в среду |
| `stores[][workTime][th][]` | `array of objects (StoreWorkTime)` | Время работы склада в четверг |
| `stores[][workTime][fr][]` | `array of objects (StoreWorkTime)` | Время работы склада в пятницу |
| `stores[][workTime][sa][]` | `array of objects (StoreWorkTime)` | Время работы склада в субботу |
| `stores[][workTime][su][]` | `array of objects (StoreWorkTime)` | Время работы склада в воскресенье |
| `stores[][name]` | `string` | Название |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/stores/{code}/edit

Создание/редактирование сведений о складе

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `store` | `object (SerializedStore)` |     |     |
| `store[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `store[email]` | `string` | `{length: {max: 255}}` | Email склада |
| `store[type]` | `string` | `[store-type-online\|store-type-retail\|store-type-supplier\|store-type-warehouse]` | Тип склада |
| `store[inventoryType]` | `string` | `[available\|integer]` | Вид остатков на складе |
| `store[address]` | `object (StoreAddress)` |     | Адрес склада |
| `store[address][index]` | `string` |     | Индекс |
| `store[address][countryIso]` | `string` |     | ISO код страны |
| `store[address][region]` | `string` |     | Регион |
| `store[address][regionId]` | `integer` |     | Идентификатор региона в Geohelper |
| `store[address][city]` | `string` |     | Город |
| `store[address][cityId]` | `integer` |     | Идентификатор города в Geohelper |
| `store[address][cityType]` | `string` |     | Тип населенного пункта |
| `store[address][street]` | `string` |     | Улица |
| `store[address][streetId]` | `integer` |     | Идентификатор улицы в Geohelper |
| `store[address][streetType]` | `string` |     | Тип улицы |
| `store[address][building]` | `string` |     | Дом |
| `store[address][flat]` | `string` |     | Номер квартиры/офиса |
| `store[address][floor]` | `integer` |     | Этаж |
| `store[address][block]` | `integer` |     | Подъезд |
| `store[address][house]` | `string` |     | Строение |
| `store[address][housing]` | `string` |     | Корпус |
| `store[address][metro]` | `string` |     | Метро |
| `store[address][notes]` | `string` |     | Примечания к адресу |
| `store[address][text]` | `string` |     | Адрес в текстовом виде |
| `store[address][coordinates]` | `object (Point)` |     | Координаты точки |
| `store[address][coordinates][latitude]` | `float` |     | Широта |
| `store[address][coordinates][longitude]` | `float` |     | Долгота |
| `store[phone]` | `object (StorePhone)` |     | Телефон склада |
| `store[phone][number]` | `string` |     | Номер телефона |
| `store[externalId]` | `string` |     | Внешний ID |
| `store[xmlId]` | `string` |     | Идентификатор 1С |
| `store[description]` | `string` |     | Описание склада |
| `store[active]` | `boolean` |     | Статус активности |
| `store[code]` | `string` |     | Символьный код |
| `store[workTime]` | `object (SerializedStoreWeekOpeningHours)` |     | Время работы склада |
| `store[workTime][mo][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в понедельник |
| `store[workTime][mo][][startTime]` | `string` |     | Время начала работы склада (в формате `H:i`) |
| `store[workTime][mo][][endTime]` | `string` |     | Время окончания работы склада (в формате `H:i`) |
| `store[workTime][mo][][lunchStartTime]` | `string` |     | Время начала перерыва (в формате `H:i`) |
| `store[workTime][mo][][lunchEndTime]` | `string` |     | Время окончания перерыва (в формате `H:i`) |
| `store[workTime][tu][]` | `array of objects (StoreWorkTime)` |     | Время работы склада во вторник |
| `store[workTime][we][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в среду |
| `store[workTime][th][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в четверг |
| `store[workTime][fr][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в пятницу |
| `store[workTime][sa][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в субботу |
| `store[workTime][su][]` | `array of objects (StoreWorkTime)` |     | Время работы склада в воскресенье |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Данные склада успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый склад успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/subscriptions

Получение списка категорий подписки

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `subscriptions[]` | `array of objects (Subscription)` | Категория подписки |
| `subscriptions[][id]` | `integer` | ID категории подписки |
| `subscriptions[][channel]` | `string` | Канал |
| `subscriptions[][name]` | `string` | Название |
| `subscriptions[][code]` | `string` | Символьный код |
| `subscriptions[][active]` | `boolean` | Статус активности |
| `subscriptions[][autoSubscribe]` | `boolean` | Автоматически подписывать новых клиентов |
| `subscriptions[][ordering]` | `integer` | Порядок |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/subscriptions/{channel}/{code}/edit

Создание/редактирование категории подписки

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `subscription` | `object (SerializedSubscription)` |     |     |
| `subscription[channel]` | `string` | `{not blank}[email\|sms\|waba]}` | Канал |
| `subscription[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `subscription[code]` | `string` | `{length: {max: 255}}{match: /^[\w\-]+$/}}` | Символьный код |
| `subscription[ordering]` | `integer` | `{not blank}{range: {>=0}}}` | Порядок |
| `subscription[active]` | `boolean` |     | Статус активности |
| `subscription[autoSubscribe]` | `boolean` |     | Автоматически подписывать новых клиентов |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданного объекта |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Новый расход успешно создан</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании расхода</p></li></ul></td></tr></tbody></table>

### GET /api/v5/reference/units

Получение списка единиц измерений

Для доступа к методу необходимо разрешение `reference_read`.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `units[]` | `array of objects (SerializedUnit)` |     |
| `units[][code]` | `string` | Символьный код |
| `units[][name]` | `string` | Название |
| `units[][sym]` | `string` | Краткое обозначение |
| `units[][default]` | `boolean` | Устанавливается по умолчанию для товаров, создаваемых в системе |
| `units[][active]` | `boolean` | Статус активности |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/reference/units/{code}/edit

Создание/редактирование единицы измерения

Для доступа к методу необходимо разрешение `reference_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `unit` | `object (SerializedUnit)` |     |     |
| `unit[code]` | `string` | `{length: {max: 255}}` | Символьный код |
| `unit[name]` | `string` | `{not blank}{length: {max: 255}}}` | Название |
| `unit[sym]` | `string` | `{not blank}{length: {max: 5}}}` | Краткое обозначение |
| `unit[default]` | `boolean` |     | Устанавливается по умолчанию для товаров, создаваемых в системе |
| `unit[active]` | `boolean` |     | Статус активности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Единица измерения создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

## Сегменты

### GET /api/v5/segments

Получение списка пользовательских сегментов

Для доступа к методу необходимо разрешение `segment_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтре `filter[type]` можно указать одно из двух значений:

- `dynamic` — возвращает динамические (пересчитываемые автоматически) сегменты;
- `static` — возвращает статические (пересчитываемые вручную) сегменты.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (SegmentFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID сегментов |
| `filter[name]` | `string` | `{length: {max: 255}}` | Название сегмента |
| `filter[isTree]` | `boolean` |     |     |
| `filter[active]` | `boolean` |     | Активность |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Дата создания (от) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Дата создания (до) |
| `filter[minCustomersCount]` | `integer` |     | Число клиентов (от) |
| `filter[maxCustomersCount]` | `integer` |     | Число клиентов (до) |
| `filter[type]` | `choice` | `[dynamic\|static]` | Тип сегмента |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `segments[]` | `array of objects (Segment)` | Сегмент |
| `segments[][id]` | `integer` | Внутренний ID сегмента |
| `segments[][code]` | `string` | Символьный код |
| `segments[][name]` | `string` | Название сегмента |
| `segments[][createdAt]` | `DateTime` | Дата создания сегмента |
| `segments[][isDynamic]` | `boolean` | Является ли сегмент автоматически пересчитываемым |
| `segments[][customersCount]` | `integer` | Количество клиентов в сегменте |
| `segments[][active]` | `boolean` | Активность сегмента |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

## Настройки

### GET /api/v5/settings

Получение настроек системы

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `settings` | `object (Settings)` | Настройки системы |
| `settings[default_currency]` | `object (Value)` | `deprecated` Валюта по умолчанию |
| `settings[default_currency][value]` | `string` | Значение настройки |
| `settings[default_currency][updated_at]` | `DateTime` | Время последнего изменения настройки |
| `settings[system_language]` | `object (Value)` | Язык системы |
| `settings[system_language][value]` | `string` | Значение настройки |
| `settings[system_language][updated_at]` | `DateTime` | Время последнего изменения настройки |
| `settings[timezone]` | `object (Value)` | Временная зона |
| `settings[timezone][value]` | `string` | Значение настройки |
| `settings[timezone][updated_at]` | `DateTime` | Время последнего изменения настройки |
| `settings[work_times][]` | `array of objects (WorkTime)` | Рабочее время |
| `settings[work_times][][day_type]` | `string` | День недели |
| `settings[work_times][][start_time]` | `string` | Начало рабочего времени |
| `settings[work_times][][end_time]` | `string` | Конец рабочего времени |
| `settings[work_times][][lunch_start_time]` | `string` | Время начала перерыва |
| `settings[work_times][][lunch_end_time]` | `string` | Время конца перерыва |
| `settings[non_working_days][]` | `array of objects (NonWorkingDay)` | Нерабочие дни |
| `settings[non_working_days][][start_date]` | `DateTime` | Начало нерабочих дней |
| `settings[non_working_days][][end_date]` | `DateTime` | Конец нерабочих дней |
| `settings[mg]` | `object (IntegrationData)` | Настройки чатов |
| `settings[mg][order_creation]` | `object (OrderCreationSettings)` | Параметры, которые будут автоматически указываться в заказе при оформлении из чатов |
| `settings[mg][order_creation][default]` | `object (ChannelSetting)` | Параметры по-умолчанию |
| `settings[mg][order_creation][default][site]` | `string` | Магазин |
| `settings[mg][order_creation][default][order_type]` | `string` | Тип заказа |
| `settings[mg][order_creation][default][order_method]` | `string` | Метод оформления заказа |
| `settings[mg][order_creation][channels][]` | `array of objects (ChannelSetting)` | Параметры для отдельных каналов (ключ - externalId канала) |
| `settings[mg][order_creation][channels][][site]` | `string` | Магазин |
| `settings[mg][order_creation][channels][][order_type]` | `string` | Тип заказа |
| `settings[mg][order_creation][channels][][order_method]` | `string` | Метод оформления заказа |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr></tbody></table>

## Склад

### GET /api/v5/store/inventories

Получение остатков и закупочных цен

Для доступа к методу необходимо разрешение `store_read`.

Метод позволяет получать информацию об остатках и закупочных ценах для торговых предложений. При указании параметра `filter[details]=1` будет также возвращена детализация остатков и закупочных цен по складам. Данные возвращаются по магазинам, доступ к котором предоставлен используемому API-ключу, либо по конкретному магазину, если указан параметр `filter[sites][]=site-code`.

По умолчанию возвращается информация как по активным, так и неактивным товарам и торговым предложениям. Для получения информации только по активным используйте параметры `filter[productActive]=1` и `filter[offerActive]=1`.

Поле `offers[][site]` возвращается в ответе только, если данные возвращаются по нескольким магазинам.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `choice` | `{not blank}[20\|50\|100\|250]}` |     |
| `page` | `string` | `{not blank}{range: {>=1}}}` |     |
| `filter` | `object (InventoryAlternativeFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID торговых предложений |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[catalogs][]` | `array of integers` |     | Массив ID каталогов |
| `filter[productExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID товара |
| `filter[productArticle][]` | `array of strings` |     | Массив артикулов товаров |
| `filter[productActive]` | `boolean` |     | Возвращать остатки только по активным товарам |
| `filter[offerExternalId][]` | `array of strings` |     | Массив внешних ID торговых предложений |
| `filter[offerXmlId][]` | `array of strings` |     | Массив XmlId торговых предложений |
| `filter[offerArticle][]` | `array of strings` |     | Массив артикулов торговых предложений |
| `filter[offerActive]` | `boolean` |     | Возвращать остатки только по активным торговым предложениям |
| `filter[details]` | `boolean` |     | Возвращать детализацию остатков по складам |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `offers[]` | `array of objects (Offer)` | Торговое предложение (SKU) |
| `offers[][id]` | `integer` | ID торгового предложения |
| `offers[][externalId]` | `string` | ID торгового предложения в магазине |
| `offers[][xmlId]` | `string` | ID торгового предложения в складской системе |
| `offers[][site]` | `string` | `deprecated` Магазин. Используйте `getCatalog()` |
| `offers[][purchasePrice]` | `double` | Закупочная цена SKU (в базовой валюте) |
| `offers[][quantity]` | `float` | Доступное количество |
| `offers[][stores][]` | `array of objects (Inventory)` | Остатки по складам |
| `offers[][stores][][quantity]` | `float` | Количество |
| `offers[][stores][][purchasePrice]` | `double` | Закупочная цена (в базовой валюте) |
| `offers[][stores][][store]` | `string` | Склад |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/inventories/upload

Обновление остатков и закупочных цен

Для доступа к методу необходимо разрешение `store_write`.

Метод позволяет обновлять остатки и закупочные цены по складам для торговых предложений. За один запрос можно обновить до 250 торговых предложений. В одном торговом предложении можно указать остатки до 500 складов.

Поле `offers[][stores][][available]` может быть целочисленным или дробным. При использовании фактического учета остатков следует передавать 0 - в случае отсутствия товара на складе, и 1 - в случае наличия.

У каждого торгового предложения должен быть указан хотя бы один из трех параметров: `id`, `xmlId` или `externalId`. В случае указания нескольких или всех перечисленных параметров, поиск сначала осуществляется по полю `id`, затем по `xmlId`, а затем по `externalId`.

Если торговое предложение относится к услуге, то переданные данные будут проигнорированы.

Если c заданным `xmlId` найдено несколько товаров, остатки будут изменены у всех.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `offers[]` | `array of objects (SerializedOffer)` |     |     |
| `offers[][id]` | `integer` |     | ID торгового предложения |
| `offers[][externalId]` | `string` |     | ID торгового предложения в магазине |
| `offers[][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `offers[][stores][]` | `array of objects (SerializedStore)` |     |     |
| `offers[][stores][][code]` | `string` |     | Символьный код |
| `offers[][stores][][available]` | `float` |     | Количество доступного товара или факт наличия |
| `offers[][stores][][purchasePrice]` | `float` |     | Закупочная цена |
| `site` | `string` |     | Символьный код магазина. Указывается в случае идентификации торговых предложений по externalId |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `processedOffersCount` | `integer` | Количество успешно обработанных торговых предложений |
| `notFoundOffers[]` | `array of objects (Offer)` | Торговое предложение (SKU) |
| `notFoundOffers[][id]` | `integer` | ID торгового предложения |
| `notFoundOffers[][externalId]` | `string` | ID торгового предложения в магазине |
| `notFoundOffers[][xmlId]` | `string` | ID торгового предложения в складской системе |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Остатки загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при загрузке остатков либо текущие настройки системы не допускают редактирование остатков</p></li></ul></td></tr></tbody></table>

### GET /api/v5/store/offers

Получение списка торговых предложений, удовлетворяющих заданному фильтру

Метод позволяет получать информацию о торговых предложениях. Данные возвращаются по магазинам, доступ к которым предоставлен используемому API-ключу, либо по конкретному магазину, если указан параметр `filter[sites][]=site-code`.

По умолчанию возвращается информация как по активным, так и неактивным торговым предложениям. Для получения информации только по активным используйте параметр `filter[active]`

Фильтры `filter[minPrice]`, `filter[maxPrice]` фильтруют по ценам торговых предложений, конвертированных в базовую валюту. Если же наряду с данными фильтрами указать фильтр по типу цены `filter[priceType]` (в который нужно передавать символьный код типа цены), то будет осуществляться фильтрация по ценам торговых предложений данного типа цены и в валюте данного типа цены.

Фильтр `filter[properties][]` позволяет получить товары по их свойствам. Фильтр необходимо задавать в формате `filter[properties][property_code_1]=value_1&filter[properties][property_code_2]=value_2`.

При передаче идентификаторов групп в параметре `filter[groups][]`, будут возвращены торговые предложения, относящиеся к товарам или услугам из указанных групп, а также для всех дочерних.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (OfferFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID торговых предложений |
| `filter[externalIds][]` | `array of strings` |     | Массив внешних ID |
| `filter[xmlIds][]` | `array of strings` |     | Массив xml ID |
| `filter[name]` | `string` | `{length: {max: 255}}` | Название/артикул товара либо артикул/штрихкод торгового предложения |
| `filter[sites][]` | `array of strings` |     | Массив кодов магазинов |
| `filter[catalogs][]` | `array of integers` |     | Массив ID каталогов |
| `filter[groups][]` | `array of integers` |     | Массив ID групп товаров или услуг |
| `filter[priceType]` | `string` |     | Тип цены |
| `filter[active]` | `boolean` | `[\|1]` | Активность |
| `filter[properties][]` | `array` |     | Свойства торговых предложений |
| `filter[sinceId]` | `custom handler result for (int)` | `{range: {>=0, <=2147483647}}` | Начиная с ID торгового предложения |
| `filter[minPrice]` | `integer` |     | Цена торгового предложения (от) |
| `filter[maxPrice]` | `integer` |     | Цена торгового предложения (до) |
| `filter[minQuantity]` | `integer` |     | Количество торгового предложения (от) |
| `filter[maxQuantity]` | `integer` |     | Количество торгового предложения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `offers[]` | `array of objects (Offer)` | Торговое предложение (SKU) |
| `offers[][images][]` | `array of strings` | Адреса изображений |
| `offers[][id]` | `integer` | ID торгового предложения |
| `offers[][externalId]` | `string` | ID торгового предложения в магазине |
| `offers[][xmlId]` | `string` | ID торгового предложения в складской системе |
| `offers[][site]` | `string` | `deprecated` Магазин. Используйте `getCatalog()` |
| `offers[][name]` | `string` | Название |
| `offers[][article]` | `string` | Артикул |
| `offers[][prices][]` | `array of objects (OfferPrice)` | Доступные цены |
| `offers[][prices][][priceType]` | `string` | Тип цены |
| `offers[][prices][][currency]` | `string` | Валюта |
| `offers[][prices][][price]` | `double` | Цена (в валюте объекта) |
| `offers[][prices][][ordering]` | `integer` | Порядок |
| `offers[][purchasePrice]` | `double` | Закупочная цена SKU (в базовой валюте) |
| `offers[][vatRate]` | `string` | Ставка НДС |
| `offers[][product]` | `object (Product)` | Товар, к которому относится SKU |
| `offers[][product][type]` | `string` | Тип (товар `product` или услуга `service`) |
| `offers[][product][minPrice]` | `double` | `deprecated` Минимальная цена на товар (в базовой валюте) |
| `offers[][product][maxPrice]` | `double` | `deprecated` Максимальная цена на товар (в базовой валюте) |
| `offers[][product][catalogId]` | `custom handler result for (int)` | ID каталога |
| `offers[][product][id]` | `integer` | ID товара |
| `offers[][product][article]` | `string` | Артикул |
| `offers[][product][name]` | `string` | Название |
| `offers[][product][url]` | `string` | Ссылка на страницу товара в магазине |
| `offers[][product][imageUrl]` | `string` | Ссылка на изображение товара |
| `offers[][product][description]` | `string` | Описание |
| `offers[][product][popular]` | `boolean` | Метка Лидер продаж |
| `offers[][product][stock]` | `boolean` | Метка Лучшая цена |
| `offers[][product][novelty]` | `boolean` | Метка Новинка |
| `offers[][product][recommended]` | `boolean` | Метка Рекомендуем |
| `offers[][product][options]` | `array` | Массив опций товара |
| `offers[][product][groups][]` | `array of objects (ProductGroup)` | Товарные группы, которым принадлежит товар |
| `offers[][product][groups][][id]` | `integer` | ID  |
| `offers[][product][groups][][externalId]` | `string` | Внешний ID товарной группы |
| `offers[][product][externalId]` | `string` | Внешний ID товара |
| `offers[][product][manufacturer]` | `string` | Производитель |
| `offers[][product][updatedAt]` | `DateTime` | Дата редактирования товара в системе |
| `offers[][product][active]` | `boolean` | Активность |
| `offers[][product][quantity]` | `double` | Количество |
| `offers[][product][markable]` | `boolean` | Подлежит маркировке |
| `offers[][properties]` | `array` | Свойства SKU |
| `offers[][quantity]` | `float` | Доступное количество |
| `offers[][weight]` | `double` | Вес |
| `offers[][length]` | `double` | Длина |
| `offers[][width]` | `double` | Ширина |
| `offers[][height]` | `double` | Высота |
| `offers[][active]` | `boolean` | Активность |
| `offers[][unit]` | `object (Unit)` | Единица измерения |
| `offers[][unit][code]` | `string` | Символьный код |
| `offers[][unit][name]` | `string` | Название |
| `offers[][unit][sym]` | `string` | Краткое обозначение |
| `offers[][barcode]` | `string` | Штрих-код |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/prices/upload

Обновление цен торговых предложений

Для доступа к методу необходимо разрешение `store_write`.

За один запрос можно передавать суммарно до 250 цен.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `prices[]` | `array of objects (PriceUploadInput)` |     |     |
| `prices[][externalId]` | `string` |     | ID торгового предложения в магазине |
| `prices[][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `prices[][id]` | `integer` |     | ID торгового предложения |
| `prices[][site]` | `string` |     | Код магазина, необходим при передаче externalId торгового предложения |
| `prices[][prices][]` | `array of objects (PriceUploadPricesInput)` |     | Цена торгового предложения |
| `prices[][prices][][code]` | `string` |     | Код типа цены |
| `prices[][prices][][price]` | `float` |     | Цена |
| `prices[][prices][][remove]` | `boolean` |     | Удалить цену |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `processedOffersCount` | `integer` | Количество успешно обработанных торговых предложений |
| `notFoundOffers[]` | `array of objects (PriceUploadNotFoundResponse)` | Список не обработанных торговых предложений |
| `notFoundOffers[][id]` | `string` |     |
| `notFoundOffers[][externalId]` | `string` | ID не обработанного торгового предложения в магазине |
| `notFoundOffers[][xmlId]` | `string` | ID не обработанного торгового предложения в складской системе |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Цены загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при загрузке цен, либо массив слишком большой</p></li></ul></td></tr></tbody></table>

### GET /api/v5/store/product-groups

Получение списка групп товаров, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `store_read`.

Метод позволяет получать информацию о товарных группах. Данные возвращаются по магазинам, доступ к которым предоставлен используемому API-ключу, либо по конкретным магазинам, если указан параметр `filter[sites][]=site-code`.

По умолчанию возвращается информация как по активным, так и неактивным товарным группам. Для получения информации только по активным используйте параметр `filter[active]`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (ProductGroupFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID групп товаров |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[catalogs][]` | `array of integers` |     | Массив ID каталогов |
| `filter[active]` | `boolean` |     | Активность |
| `filter[parentGroupId]` | `integer` | `{range: {>=0, <=100000000000}}` | ID родительской группы товаров |
| `filter[loadFromYml]` | `boolean` |     | Каталог загружен из ICML файла |
| `filter[minLevel]` | `integer` |     | Минимальный уровень вложенности |
| `filter[maxLevel]` | `integer` |     | Максимальный уровень вложенности |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `productGroup[]` | `array of objects (ProductGroup)` | Товарная группа |
| `productGroup[][parentId]` | `integer` | ID родительской группы |
| `productGroup[][site]` | `string` | Магазин |
| `productGroup[][id]` | `integer` | ID  |
| `productGroup[][name]` | `string` | Название |
| `productGroup[][lvl]` | `custom handler result for (int)` | Уровень вложенности |
| `productGroup[][externalId]` | `string` | Внешний ID товарной группы |
| `productGroup[][active]` | `boolean` | Активность |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/product-groups/create

Добавление товарной группы

Для доступа к методу необходимо разрешение `store_write`.

Метод позволяет создавать товарные группы. В случае успеха возвращает внутренний ID созданной товарной группы.

Для установки магазина необходимо указать код магазина в параметре `productGroup[site]`. Будет выполнен поиск активного магазина по коду.

Для установки родительской товарной группы необходимо указать один из параметров: `productGroup[parentId]` или `productGroup[parentExternalId]`. Сначала будет выполнен поиск активной товарной группы по параметру `productGroup[parentId]`, затем по `productGroup[parentExternalId]` и `productGroup[site]`.

Если не указан параметр `productGroup[active]` будет установлено значение по-умолчанию `true`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `productGroup` | `object (SerializedProductGroup)` |     |     |
| `productGroup[parentId]` | `integer` |     | ID родительской группы |
| `productGroup[name]` | `string` |     | Название |
| `productGroup[description]` | `string` |     | Описание |
| `productGroup[externalId]` | `string` |     | Внешний ID товарной группы |
| `productGroup[active]` | `boolean` |     | Активность |
| `productGroup[parentExternalId]` | `string` |     | Внешний ID родительской товарной группы |
| `productGroup[site]` | `string` |     | Код магазина |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID созданной товарной группы |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Товарная группа успешно создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании товарной группы</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/product-groups/{externalId}/edit

Редактирование товарной группы

Для доступа к методу необходимо разрешение `store_write`.

Метод позволяет вносить изменения в товарные группы. В случае успеха возвращает внутренний ID изменённой товарной группы.

Поиск товарной группы возможен как по внешнему ID (`externalId`), так и по внутреннему ID (`id`). Для этого необходимо указать соответствующее значение в параметре `by` (по умолчанию `externalId`). В случае поиска по внешнему ID необходимо передать параметр `site`.

Для установки родительской товарной группы необходимо указать один из параметров: `productGroup[parentId]` или `productGroup[parentExternalId]`. Сначала будет выполнен поиск активной товарной группы по параметру `productGroup[parentId]`, затем по `productGroup[parentExternalId]` и `productGroup[site]`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `by` | `string` |     | Указывается, что передается в параметре externalId: внутренний (by=id) или внешний (by=externalId) ID товарной группы. По умолчанию externalId. |
| `site` | `string` |     | Символьный код магазина. Указывается в случае поиска товарной группы по externalId (by=externalId) |
| `productGroup` | `object (SerializedProductGroup)` |     |     |
| `productGroup[parentId]` | `integer` |     | ID родительской группы |
| `productGroup[name]` | `string` |     | Название |
| `productGroup[description]` | `string` |     | Описание |
| `productGroup[externalId]` | `string` |     | Внешний ID товарной группы |
| `productGroup[active]` | `boolean` |     | Активность |
| `productGroup[parentExternalId]` | `string` |     | Внешний ID родительской товарной группы |
| `productGroup[site]` | `string` |     | Код магазина |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `id` | `integer` | Внутренний ID изменённой товарной группы |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Товарная группа успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении товарной группы</p></li></ul></td></tr></tbody></table>

### GET /api/v5/store/products

Получение списка товаров с торговыми предложениями, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `store_read`.

Метод позволяет получать информацию о товарах и их торговых предложениях. Данные возвращаются по магазинам, доступ к которым предоставлен используемому API-ключу, либо по конкретному магазину, если указан параметр `filter[sites][]=site-code`.

При реализации постоянной трансляции изменений во внешнюю систему **рекомендуется** использовать подход с забором инкрементальных изменений через `filter[sinceId]` передавая id последнего полученного товара.

По умолчанию возвращается информация как по активным, так и неактивным товарам. Для получения информации только по активным используйте параметр `filter[active]`

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Фильтры `filter[minPrice]`, `filter[maxPrice]` фильтруют по ценам торговых предложений, конвертированных в базовую валюту. Если же наряду с данными фильтрами указать фильтр по типу цены `filter[priceType]` (в который нужно передавать символьный код типа цены), то будет осуществляться фильтрация по ценам торговых предложений данного типа цены и в валюте данного типа цены.

Фильтр `filter[properties][]` позволяет получить товары по их свойствам. Фильтр необходимо задавать в формате `filter[properties][property_code_1]=value_1&filter[properties][property_code_2]=value_2`.

В фильтре `filter[groups]` указываются ID групп товаров.

Фильтр `filter[classSegment]` позволяет получить сегменты ABC/XYZ-анализа товаров. Доступны следующие значения: `abc[0..2]_xyz[0..2]`.

Фильтры `filter[offerIds][]`, `filter[offerExternalId]`, `filter[offerXmlId]` позволяют получить товары, которым принадлежат торговые предложения с заданными `id`, `externalId`, `xmlId` соответственно.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (ProductFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID товаров |
| `filter[name]` | `string` | `{length: {max: 255}}` | Название/артикул товара либо артикул/штрихкод торгового предложения |
| `filter[groups][]` | `array of integers` |     | Группа товара |
| `filter[sites][]` | `array of strings` |     | Магазины |
| `filter[catalogs][]` | `array of integers` |     | Массив ID каталогов |
| `filter[priceType]` | `string` |     | Тип цены |
| `filter[manufacturer]` | `string` | `{length: {max: 255}}` | Производитель |
| `filter[externalId]` | `string` | `{length: {max: 255}}` | Внешний ID |
| `filter[xmlId]` | `string` | `{length: {max: 255}}` | Xml ID |
| `filter[url]` | `string` | `{length: {max: 2000}}` | URL |
| `filter[urlLike]` | `string` | `{length: {max: 2000}}` | Частичное совпадение URL (игнорируя домен и query-параметры) |
| `filter[classSegment]` | `string` | `{length: {max: 255}}{match: /^abc[0-2]_xyz[0-2]$/}}` | ABC/XYZ-сегмент |
| `filter[active]` | `boolean` |     | Активность |
| `filter[popular]` | `boolean` |     | Метка Лидер продаж |
| `filter[stock]` | `boolean` |     | Метка Лучшая цена |
| `filter[novelty]` | `boolean` |     | Метка Новинка |
| `filter[recommended]` | `boolean` |     | Метка Рекомендуем |
| `filter[properties][]` | `array` |     | Свойства товаров |
| `filter[markable]` | `boolean` |     |     |
| `filter[offerIds][]` | `array of integers` |     | Массив ID торговых предложений |
| `filter[offerExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID торгового предложения |
| `filter[offerXmlId]` | `string` | `{length: {max: 255}}` | XmlId торгового предложения |
| `filter[groupExternalId]` | `string` | `{length: {max: 255}}` | Внешний ID товарной группы |
| `filter[sinceUpdatedAt]` | `DateTime` | `Y-m-d H:i:s` | Нижнее ограничение по дате изменения товара (исключая границу) |
| `filter[sinceId]` | `custom handler result for (int)` | `{range: {>=0, <=2147483647}}` | Начиная с ID товара |
| `filter[productType]` | `custom handler result for (int)` |     |     |
| `filter[minPrice]` | `integer` |     | Цена товара (от) |
| `filter[maxPrice]` | `integer` |     | Цена товара (до) |
| `filter[minPurchasePrice]` | `integer` |     | Закупочная цена товара (от) |
| `filter[maxPurchasePrice]` | `integer` |     | Закупочная цена товара (до) |
| `filter[minQuantity]` | `integer` |     | Количество товара (от) |
| `filter[maxQuantity]` | `integer` |     | Количество товара (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `products[]` | `array of objects (Product)` | Товар |
| `products[][type]` | `string` | Тип (товар `product` или услуга `service`) |
| `products[][minPrice]` | `double` | `deprecated` Минимальная цена на товар (в базовой валюте) |
| `products[][maxPrice]` | `double` | `deprecated` Максимальная цена на товар (в базовой валюте) |
| `products[][catalogId]` | `custom handler result for (int)` | ID каталога |
| `products[][id]` | `integer` | ID товара |
| `products[][article]` | `string` | Артикул |
| `products[][name]` | `string` | Название |
| `products[][url]` | `string` | Ссылка на страницу товара в магазине |
| `products[][imageUrl]` | `string` | Ссылка на изображение товара |
| `products[][description]` | `string` | Описание |
| `products[][popular]` | `boolean` | Метка Лидер продаж |
| `products[][stock]` | `boolean` | Метка Лучшая цена |
| `products[][novelty]` | `boolean` | Метка Новинка |
| `products[][recommended]` | `boolean` | Метка Рекомендуем |
| `products[][options]` | `array` | Массив опций товара |
| `products[][groups][]` | `array of objects (ProductGroup)` | Товарные группы, которым принадлежит товар |
| `products[][groups][][id]` | `integer` | ID  |
| `products[][groups][][externalId]` | `string` | Внешний ID товарной группы |
| `products[][externalId]` | `string` | Внешний ID товара |
| `products[][manufacturer]` | `string` | Производитель |
| `products[][offers][]` | `array of objects (Offer)` | Торговые предложения |
| `products[][offers][][name]` | `string` | Название |
| `products[][offers][][price]` | `double` | `deprecated` Цена SKU |
| `products[][offers][][images][]` | `array of strings` | Адреса изображений |
| `products[][offers][][id]` | `integer` | ID торгового предложения |
| `products[][offers][][externalId]` | `string` | ID торгового предложения в магазине |
| `products[][offers][][xmlId]` | `string` | ID торгового предложения в складской системе |
| `products[][offers][][article]` | `string` | Артикул |
| `products[][offers][][prices][]` | `array of objects (OfferPrice)` | Доступные цены |
| `products[][offers][][prices][][priceType]` | `string` | Тип цены |
| `products[][offers][][prices][][currency]` | `string` | Валюта |
| `products[][offers][][prices][][price]` | `double` | Цена (в валюте объекта) |
| `products[][offers][][prices][][ordering]` | `integer` | Порядок |
| `products[][offers][][purchasePrice]` | `double` | Закупочная цена SKU (в базовой валюте) |
| `products[][offers][][vatRate]` | `string` | Ставка НДС |
| `products[][offers][][properties]` | `array` | Свойства SKU |
| `products[][offers][][quantity]` | `float` | Доступное количество |
| `products[][offers][][weight]` | `double` | Вес |
| `products[][offers][][length]` | `double` | Длина |
| `products[][offers][][width]` | `double` | Ширина |
| `products[][offers][][height]` | `double` | Высота |
| `products[][offers][][active]` | `boolean` | Активность |
| `products[][offers][][unit]` | `object (Unit)` | Единица измерения |
| `products[][offers][][unit][code]` | `string` | Символьный код |
| `products[][offers][][unit][name]` | `string` | Название |
| `products[][offers][][unit][sym]` | `string` | Краткое обозначение |
| `products[][offers][][barcode]` | `string` | Штрих-код |
| `products[][updatedAt]` | `DateTime` | Дата редактирования товара в системе |
| `products[][active]` | `boolean` | Активность |
| `products[][quantity]` | `double` | Количество |
| `products[][markable]` | `boolean` | Подлежит маркировке |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/products/batch/create

Пакетное добавление товаров и услуг

Для доступа к методу необходимо разрешение `store_write`.

За один запрос можно передавать суммарно до 50 товаров.

В параметре `type` можно указать, создается товар (`product`) или услуга (`service`). По умолчанию создается товар.

У каждого товара должны быть указаны параметры `name` и `catalogId`.

У каждой товарной группы должен быть указан один из параметров: `id` или `externalId`. В случае указания нескольких параметров поиск осуществляется сперва по `id`, но только в рамках каталога товара.

Для услуг игнорируются поля «Производитель» (`manufacturer`) и «Подлежит маркировке» (`markable`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `products[]` | `array of objects (ProductCreateInput)` |     |     |
| `products[][type]` | `string` |     | Тип (товар `product` или услуга `service`) |
| `products[][catalogId]` | `integer` |     | ID каталога |
| `products[][article]` | `string` |     | Артикул |
| `products[][name]` | `string` |     | Название |
| `products[][url]` | `string` |     | Ссылка на страницу товара в магазине |
| `products[][description]` | `string` |     | Описание |
| `products[][popular]` | `boolean` |     | Метка Лидер продаж |
| `products[][stock]` | `boolean` |     | Метка Лучшая цена |
| `products[][novelty]` | `boolean` |     | Метка Новинка |
| `products[][recommended]` | `boolean` |     | Метка Рекомендуем |
| `products[][groups][]` | `array of objects (ProductEditGroupInput)` |     | Товарные группы, которым принадлежит товар |
| `products[][groups][][externalId]` | `string` |     | Внешний ID товарной группы |
| `products[][groups][][id]` | `integer` |     | ID товарной группы |
| `products[][externalId]` | `string` |     | Внешний ID товара |
| `products[][manufacturer]` | `string` |     | Производитель |
| `products[][active]` | `boolean` |     | Активность |
| `products[][markable]` | `boolean` |     | Подлежит маркировке |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `processedProductsCount` | `integer` | Количество успешно обработанных товаров |
| `addedProducts[]` | `array of integers` | Список id добавленных товаров |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Товары успешно добавлены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при добавлении товаров, либо массив слишком большой</p></li></ul></td></tr></tbody></table>

### POST /api/v5/store/products/batch/edit

Пакетное редактирование товаров и услуг

Для доступа к методу необходимо разрешение `store_write`.

За один запрос можно передавать суммарно до 50 товаров.

У каждого товара должен быть указан параметр `id`, либо параметры `externalId` и `site`. В случае указания нескольких или всех перечисленных параметров, поиск сначала осуществляется по полю `id`, затем по `externalId` и `site`.

В редактировании участвуют только те параметры товара, которые были переданы в запросе. При редактировании каталога товара выполняется очистка присвоенных товарных групп, свойств и опций.

У каждой товарной группы должен быть указан один из параметров: `id` или `externalId`. Поиск осуществляется аналогично товарам, но только в рамках каталога товара. Если в параметре `groups` передан пустой массив, выполнится очистка присвоенных товарных групп.

Для услуг игнорируются поля «Производитель» (`manufacturer`) и «Подлежит маркировке» (`markable`).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `products[]` | `array of objects (ProductEditInput)` |     |     |
| `products[][catalogId]` | `integer` |     | ID каталога |
| `products[][id]` | `integer` |     | ID товара |
| `products[][article]` | `string` |     | Артикул |
| `products[][name]` | `string` |     | Название |
| `products[][url]` | `string` |     | Ссылка на страницу товара в магазине |
| `products[][description]` | `string` |     | Описание |
| `products[][popular]` | `boolean` |     | Метка Лидер продаж |
| `products[][stock]` | `boolean` |     | Метка Лучшая цена |
| `products[][novelty]` | `boolean` |     | Метка Новинка |
| `products[][recommended]` | `boolean` |     | Метка Рекомендуем |
| `products[][groups][]` | `array of objects (ProductEditGroupInput)` |     | Товарные группы, которым принадлежит товар |
| `products[][groups][][externalId]` | `string` |     | Внешний ID товарной группы |
| `products[][groups][][id]` | `integer` |     | ID товарной группы |
| `products[][externalId]` | `string` |     | Внешний ID товара |
| `products[][manufacturer]` | `string` |     | Производитель |
| `products[][active]` | `boolean` |     | Активность |
| `products[][markable]` | `boolean` |     | Подлежит маркировке |
| `products[][site]` | `string` |     | Код магазина, необходим при передаче externalId товара |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `processedProductsCount` | `integer` | Количество успешно обработанных товаров |
| `notFoundProducts[]` | `array of objects (ProductEditNotFoundResponse)` | Список необработанных товаров |
| `notFoundProducts[][id]` | `string` | ID необработанного товара |
| `notFoundProducts[][externalId]` | `string` | Внешний ID необработанного товара |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Товары успешно изменены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении товаров, либо массив слишком большой</p></li></ul></td></tr></tbody></table>

### GET /api/v5/store/products/properties

Получение списка свойств товаров, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `store_read`.

Метод позволяет получать информацию о свойствах товаров. Данные возвращаются по магазинам, доступ к которым предоставлен используемому API-ключу, либо по конкретным магазинам, если указан параметр `filter[sites][]=site-code`.

Поле `visible` определяет видимость характеристики товара для покупателя. Если поле имеет значение `false`, то характеристика рекомендуется для отображения только внутри системы.

Поле `variative` определяет, задействована ли характеристика при построении вариаций. Если поле имеет значение `true`, то характеристика задействована при построении вариативности товара.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Свойства товаров можно отфильтровать по идентификаторам `filter[ids]`, наименованию `filter[name]` (частичное совпадение), символьному коду `filter[code]`, видимости `filter[visible]` и вариативности `filter[variative]`.

При передаче идентификаторов групп в параметре `filter[groups][]`, будут возвращены свойства для указанных групп, а также для всех дочерних.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (ProductPropertiesFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID товаров или услуг |
| `filter[name]` | `string` |     | Название товара или услуги |
| `filter[code]` | `string` |     | Символьный код товара или услуги |
| `filter[sites][]` | `array of strings` |     | Массив кодов магазинов |
| `filter[visible]` | `boolean` |     | Видимость свойства |
| `filter[variative]` | `boolean` |     | Вариативность свойства |
| `filter[catalogs][]` | `array of integers` |     | Массив ID каталогов |
| `filter[groups][]` | `array of integers` |     | Массив ID групп товаров или услуг |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `properties[]` | `array of objects (ProductProperty)` | Свойство товара |
| `properties[][sites][]` | `array of strings` | Символьные коды сайтов к которым привязан каталог |
| `properties[][groups][]` | `array of objects (ProductPropertyGroup)` | Группы, содержащие товары с данным свойством |
| `properties[][groups][][id]` | `integer` | ID группы |
| `properties[][groups][][name]` | `string` | Наименование группы |
| `properties[][code]` | `string` | Символьный код свойства |
| `properties[][name]` | `string` | Наименование свойства |
| `properties[][isNumeric]` | `boolean` | Числовое свойство |
| `properties[][visible]` | `boolean` | Видимость свойства |
| `properties[][variative]` | `boolean` | Вариативность свойства |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/store/products/properties/values

Получение списка значений свойств товаров или услуг, удовлетворяющих заданному фильтру

Метод позволяет получать информацию о значениях свойств товаров или услуг. Данные возвращаются по магазинам, доступ к которым предоставлен используемому API-ключу.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

Свойства товаров можно отфильтровать по идентификаторам `filter[ids]`, наименованию `filter[name]` (частичное совпадение), символьному коду `filter[code]`, видимости `filter[visible]` и вариативности `filter[variative]`.

При передаче идентификаторов групп в параметре `filter[groups][]`, будут возвращены свойства для указанных групп, а также для всех дочерних.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (ProductPropertyValuesFilterData)` |     |     |
| `filter[propertyName]` | `string` |     | Название свойства |
| `filter[propertyCode]` | `string` |     | Код свойства |
| `filter[groups][]` | `array of integers` |     | Массив ID групп товаров или услуг |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `productPropertyValues[]` | `array of objects (ProductPropertyValueResponse)` |     |
| `productPropertyValues[][property]` | `object (ProductPropertyResponse)` |     |
| `productPropertyValues[][property][code]` | `string` | Символьный код свойства |
| `productPropertyValues[][property][name]` | `string` | Название свойства |
| `productPropertyValues[][value]` | `string` | Значение свойства |
| `productPropertyValues[][offersCount]` | `custom handler result for (int)` | Количество использований в торговых предложениях |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["store"\]\["actions"\]\["inventoriesActualize"\]}

Актуализация остатков после обращения к складской системе

Метод позволяет актуализировать остатки по складам для торговых предложений. Для актуализации остатков система инициирует POST запрос метода, указанного в `integrationModule["integrations"]["store"]["actions"]["inventoriesActualize"]`. Торговые предложения, отсутствующие в запросе будут игнорироваться в ответе.

У каждого торгового предложения должен быть указан хотя бы один из трех параметров: `id`, `xmlId` или `externalId`. В случае указания нескольких или всех перечисленных параметров, поиск сначала осуществляется по полю `id`, затем по `xmlId`, а затем по `externalId`.

В случае возникновения ошибки во внешнем ресурсе, к которому обращается callback-метод, данные по ошибке будут внесены в журнал действий (Настройки > Системные > Журнал действий).

В случае отмены резервирования будет инициирован POST запрос метода, указанного в `integrationModule["integrations"]["store"]["actions"]["inventoriesActualize"]` с параметром `packs[][quantity]` равным 0.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `order` | `object (OrderDataModel)` |     | Заказ |
| `order[id]` | `integer` |     | ID заказа |
| `order[externalId]` | `string` |     | Внешний ID заказа |
| `order[site]` | `string` |     | Магазин |
| `packs[]` | `array of objects (PackDataModel)` |     | Упаковки товаров |
| `packs[][item]` | `object (PackItemModel)` |     | Позиция в заказе |
| `packs[][item][id]` | `integer` |     | ID позиции в заказе |
| `packs[][item][externalIds][]` | `array of objects (CodeValueModel)` |     | Внешние идентификаторы позиции в заказе |
| `packs[][offer]` | `object (OfferDataModel)` |     | Торговое предложение |
| `packs[][offer][id]` | `integer` |     | ID торгового предложения |
| `packs[][offer][externalId]` | `string` |     | Внешний ID торгового предложения |
| `packs[][offer][xmlId]` | `string` |     | Xml ID торгового предложения |
| `packs[][quantity]` | `float` |     | Количество товара в упаковке |
| `packs[][store]` | `string` |     | Склад |
| `packs[][purchasePrice]` | `float` |     | Закупочная цена пака |
| `packs[][shipmentDate]` | `DateTime` |     | Дата забора пака |
| `packs[][invoiceNumber]` | `string` |     | Номер счет-фактуры |
| `packs[][deliveryNoteNumber]` | `string` |     | Номер товарной накладной |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `inventories[]` | `array of objects (InventoriesDataModel)` | Остатки |
| `inventories[][offers][]` | `array of objects (SerializedOffer)` |     |
| `inventories[][offers][][id]` | `integer` | ID торгового предложения |
| `inventories[][offers][][externalId]` | `string` | ID торгового предложения в магазине |
| `inventories[][offers][][xmlId]` | `string` | ID торгового предложения в складской системе |
| `inventories[][offers][][stores][]` | `array of objects (SerializedStore)` |     |
| `inventories[][offers][][stores][][code]` | `string` | Символьный код |
| `inventories[][offers][][stores][][available]` | `float` | Количество доступного товара или факт наличия |
| `inventories[][offers][][stores][][purchasePrice]` | `float` | Закупочная цена |

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["store"\]\["actions"\]\["inventoriesUpload"\]}

Обновление остатков и закупочных цен

Метод позволяет обновлять остатки и закупочные цены по складам для торговых предложений. Для обновления остатков система инициирует POST запрос метода, указанного в `integrationModule["integrations"]["store"]["actions"]["inventoriesUpload"]`. Система передает перечень торговых предложений для которых необходимо передать информацию об остатках, в зависимости от контекста вызова метода. Торговые предложения, отсутствующие в запросе, будут игнорироваться в ответе. Точки вызова метода настраиваются в конфигурации в поле `integrationModule["integrations"]["store"]["actions"]["inventoriesUpload"]["callPoints"]`.

У каждого торгового предложения должен быть указан хотя бы один из трех параметров: `id`, `xmlId` или `externalId`. В случае указания нескольких или всех перечисленных параметров, поиск сначала осуществляется по полю `id`, затем по `xmlId`, а затем по `externalId`.

Поле `offers[][stores][][available]` может быть целочисленным или дробным. При использовании фактического учета остатков следует передавать 0 - в случае отсутствия товара на складе, и 1 - в случае наличия.

В случае возникновения ошибки во внешнем ресурсе, к которому обращается callback-метод, данные по ошибке будут внесены в журнал действий (Настройки > Системные > Журнал действий).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` |     |     |
| `offers[]` | `array of objects (SerializedOffer)` |     |     |
| `offers[][id]` | `integer` |     | ID торгового предложения |
| `offers[][externalId]` | `string` |     | ID торгового предложения в магазине |
| `offers[][xmlId]` | `string` |     | ID торгового предложения в складской системе |
| `offers[][site]` | `string` |     | `deprecated` Магазин. Используйте `getCatalog()` |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `errorMsg` | `string` | Текст ошибки |
| `offers[]` | `array of objects (SerializedOffer)` |     |
| `offers[][id]` | `integer` | ID торгового предложения |
| `offers[][externalId]` | `string` | ID торгового предложения в магазине |
| `offers[][xmlId]` | `string` | ID торгового предложения в складской системе |
| `offers[][stores][]` | `array of objects (SerializedStore)` |     |
| `offers[][stores][][code]` | `string` | Символьный код |
| `offers[][stores][][available]` | `float` | Количество доступного товара или факт наличия |
| `offers[][stores][][purchasePrice]` | `float` | Закупочная цена |
| `offers[][site]` | `string` | `deprecated` Магазин. Используйте `getCatalog()` |

## Задачи

### GET /api/v5/tasks

Получение списка задач

Для доступа к методу необходимо разрешение `task_read`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

В фильтре `filter[ids][]` передаётся массив внутренних идентификаторов задач.

В фильтре `filter[creators][]` передаётся массив внутренних идентификаторов пользователей.

В фильтре `filter[performers][]` передаётся массив внутренних идентификаторов пользователей или групп.

Фильтр `filter[status]` позволяет получить задачи, находящиеся в определенном статусе. Доступны следующие значения:

- `completed` - завершённые задачи;
- `performing` - незавершённые задачи.

Фильтром `filter[customer]` можно производить поиск по ФИО, email или телефону клиента.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (TaskFilterData)` |     |     |
| `filter[ids][]` | `array of integers` |     | Массив ID задач |
| `filter[orderNumber]` | `string` | `{length: {max: 255}}` | Номер заказа, связанного с задачей |
| `filter[customer]` | `string` |     | Клиент, связанный с задачей |
| `filter[performers][]` | `array of integers` |     | Исполнители задачи |
| `filter[status]` | `string` | `[completed\|performing]` | Статус задачи |
| `filter[creators][]` | `array of integers` |     | Авторы задачи |
| `filter[text]` | `string` | `{length: {max: 255}}` | Текст задачи |
| `filter[tagsFilter]` | `object (TagsFilter)` |     |     |
| `filter[tagsFilter][matchMode]` | `object (EntityTagFilterMatchMode)` |     |     |
| `filter[tagsFilter][matchMode][name]` | `string` |     |     |
| `filter[tagsFilter][matchMode][value]` | `string` |     |     |
| `filter[tagsFilter][attached]` | `custom handler result for (bool)` |     |     |
| `filter[tagsFilter][without]` | `custom handler result for (bool)` |     |     |
| `filter[tags][]` | `array of strings` |     |     |
| `filter[attachedTags][]` | `array of strings` |     |     |
| `filter[createdAtFrom]` | `DateTime` | `Y-m-d` | Дата создания задачи (с) |
| `filter[createdAtTo]` | `DateTime` | `Y-m-d` | Дата создания задачи (до) |
| `filter[dateFrom]` | `DateTime` | `Y-m-d` | Дата выполнения задачи (с) |
| `filter[dateTo]` | `DateTime` | `Y-m-d` | Дата выполнения задачи (до) |
| `filter[completedAtFrom]` | `DateTime` | `Y-m-d` | Фактическая дата выполнения (с) |
| `filter[completedAtTo]` | `DateTime` | `Y-m-d` | Фактическая дата выполнения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `tasks[]` | `array of objects (Task)` | Задача |
| `tasks[][id]` | `integer` | ID задачи |
| `tasks[][text]` | `string` | Текст задачи |
| `tasks[][commentary]` | `string` | Комментарий к задаче |
| `tasks[][datetime]` | `DateTime` | Время выполнения задачи |
| `tasks[][createdAt]` | `DateTime` | Дата создания |
| `tasks[][complete]` | `boolean` | Признак выполнения задачи |
| `tasks[][creator]` | `integer` | Автор задачи |
| `tasks[][performer]` | `integer` | Исполнитель задачи |
| `tasks[][performerType]` | `string` | Тип исполнителя задачи |
| `tasks[][customer]` | `object (AbstractCustomer)` | Клиент, к которому привязана задача |
| `tasks[][customer][type]` | `string` | Тип клиента |
| `tasks[][customer][id]` | `integer` | ID клиента |
| `tasks[][customer][externalId]` | `string` | Внешний ID клиента |
| `tasks[][customer][site]` | `string` | Магазин, с которого пришел клиент |
| `tasks[][order]` | `object (Order)` | Заказ, к которому привязана задача |
| `tasks[][order][id]` | `integer` | ID заказа |
| `tasks[][order][number]` | `string` | Номер заказа |
| `tasks[][order][externalId]` | `string` | Внешний ID заказа |
| `tasks[][order][site]` | `string` | Магазин |
| `tasks[][phone]` | `string` | Телефон связанный с задачей |
| `tasks[][phoneSite]` | `string` | Магазин, связанный с задачей на перезвон |
| `tasks[][completedAt]` | `DateTime` | Время завершения задачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/tasks/create

Создание задачи

Для доступа к методу необходимо разрешение `task_write`.

Метод создает задачу и возвращает внутренний ID созданной задачи.

Если требуется установить дату выполнения задачи, необходимо передать дату в формате `Y-m-d H:i` в поле `task[datetime]`. Дату необходимо указать в будущем времени.

В поле `task[performerId]` необходимо передать внутренний ID пользователя или группы, которой необходимо назначить задачу.

Если требуется привязать задачу к существующему клиенту, то необходимо установить значение одного из следующих полей:

- `task[customer][id]` – внутренний ID клиента;
- `task[customer][externalId]` – внешний ID клиента.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск клиента будет осуществляться в рамках магазинов, к которым есть доступ у используемого API-ключа. Если клиент не будет найден, связь не будет установлена.

Если требуется привязать задачу к существующему заказу, то необходимо установить значение одного из следующих полей:

- `task[order][id]` – внутренний ID заказа;
- `task[order][externalId]` – внешний ID заказа;
- `task[order][number]` – номер заказа.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск заказа будет осуществляться в рамках магазинов, к которым есть доступ у используемого API-ключа. Если заказ не будет найден, связь не будет установлена.

При связывании задачи с существующим заказом, задача будет автоматически привязана к клиенту, к которому относится заказ.

При связывании задачи с клиентом/заказом, необходимо указать данные либо заказа, либо клиента.

При создании задачи на перезвон необходимо в поле `task[phone]` указать номер телефона, по которому следует перезвонить. В поле `task[phoneSite]` желательно указать символьный код магазина, чтобы иметь возможность осуществить перезвон с номера указанного магазина. Данная возможность зависит от используемой телефонии и её настроек.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `task` | `object (SerializedTask)` |     |     |
| `task[text]` | `string` |     | Текст задачи |
| `task[commentary]` | `string` |     | Комментарий к задаче |
| `task[datetime]` | `DateTime` |     | Время выполнения задачи |
| `task[complete]` | `boolean` |     | Признак выполнения задачи |
| `task[phone]` | `string` |     | Телефон связанный с задачей |
| `task[customer]` | `object (SerializedEntityCustomer)` |     | Клиент, к которому привязана задача |
| `task[customer][id]` | `integer` |     | Внутренний ID клиента |
| `task[customer][externalId]` | `string` |     | Внешний ID клиента |
| `task[performerId]` | `integer` |     | Исполнитель задачи |
| `task[order]` | `object (SerializedEntityOrder)` |     | Заказ, к которому привязана задача |
| `task[order][id]` | `integer` |     | Внутренний ID заказа |
| `task[order][externalId]` | `string` |     | Внешний ID заказа |
| `task[order][number]` | `string` |     | Номер заказа |
| `task[phoneSite]` | `string` |     | Магазин, связанный с задачей на перезвон |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Задача создана</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при создании задачи</p></li></ul></td></tr></tbody></table>

### GET /api/v5/tasks/history

Получение истории изменения задач

Для доступа к методу необходимо разрешение `task_read`.

Возвращает изменения в задачах, произведенные в указанный диапазон дат (используя фильтры `filter[startDate]` и `filter[endDate]`), либо инкрементальные изменения (используя `filter[sinceId]`). При реализации постоянной трансляции изменений во внешнюю систему ​**рекомендуется**​ использовать подход с забором инкрементальных изменений через `filter[sinceId]` передавая `id` последней полученной записи истории.

Для записей создания задачи возвращается полный набор полей в контексте `task`.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке. Для постраничного перебора записей истории ​**необходимо**​ использовать `filter[sinceId]`. Параметр `page` использовать не рекомендуется.

Более подробно о работе с историей вы можете ознакомиться в [отдельной статье документации](https://docs.retailcrm.ru/Developers/API/APIFeatures/WorkingHistoryAPI).

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (TaskHistoryFilterType)` |     |     |
| `filter[taskId]` | `integer` | `{range: {>=0, <=4294967295}}{not blank}}` | ID задачи |
| `filter[sinceId]` | `integer` | `{range: {>=0, <=4294967295}}{not blank}}` | Начиная с ID истории задач |
| `filter[startDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (от) |
| `filter[endDate]` | `DateTime` | `Y-m-d H:i:s` | Дата/время изменения (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `generatedAt` | `DateTime` | Время формирования ответа |
| `history[]` | `array of objects (TaskHistory)` |     |
| `history[][id]` | `integer` | Внутренний идентификатор записи в истории |
| `history[][createdAt]` | `DateTime` | Дата внесения изменения |
| `history[][created]` | `boolean` | Признак создания сущности |
| `history[][source]` | `string` | Источник изменения |
| `history[][user]` | `object (User)` | Пользователь |
| `history[][user][id]` | `integer` | ID пользователя |
| `history[][field]` | `string` | Имя изменившегося поля |
| `history[][oldValue]` | `custom handler result for (mixed)` | Старое значение свойства |
| `history[][newValue]` | `custom handler result for (mixed)` | Новое значение свойства |
| `history[][apiKey]` | `object (ApiKey)` | Информация о ключе api, использовавшемся для этого изменения |
| `history[][apiKey][current]` | `boolean` | Изменение было сделано с помощью ключа, используемого в данный момент |
| `history[][apiKey][id]` | `integer` | ID API-ключа |
| `history[][task]` | `object (Task)` | Задача |
| `history[][task][id]` | `integer` | ID задачи |
| `history[][comment]` | `object (TaskComment)` | Комментарий пользователя к задаче |
| `history[][comment][id]` | `integer` | ID комментария к задаче |
| `history[][comment][text]` | `string` | Текст комментария к задаче |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/tasks/{id}

Получение информации о задаче

Для доступа к методу необходимо разрешение `task_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID задачи |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `task` | `object (Task)` | Задача |
| `task[id]` | `integer` | ID задачи |
| `task[text]` | `string` | Текст задачи |
| `task[commentary]` | `string` | Комментарий к задаче |
| `task[datetime]` | `DateTime` | Время выполнения задачи |
| `task[createdAt]` | `DateTime` | Дата создания |
| `task[complete]` | `boolean` | Признак выполнения задачи |
| `task[creator]` | `integer` | Автор задачи |
| `task[performer]` | `integer` | Исполнитель задачи |
| `task[performerType]` | `string` | Тип исполнителя задачи |
| `task[customer]` | `object (AbstractCustomer)` | Клиент, к которому привязана задача |
| `task[customer][type]` | `string` | Тип клиента |
| `task[customer][id]` | `integer` | ID клиента |
| `task[customer][externalId]` | `string` | Внешний ID клиента |
| `task[customer][site]` | `string` | Магазин, с которого пришел клиент |
| `task[order]` | `object (Order)` | Заказ, к которому привязана задача |
| `task[order][id]` | `integer` | ID заказа |
| `task[order][number]` | `string` | Номер заказа |
| `task[order][externalId]` | `string` | Внешний ID заказа |
| `task[order][site]` | `string` | Магазин |
| `task[phone]` | `string` | Телефон связанный с задачей |
| `task[phoneSite]` | `string` | Магазин, связанный с задачей на перезвон |
| `task[completedAt]` | `DateTime` | Время завершения задачи |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Задача не найдена</p></li></ul></td></tr></tbody></table>

### GET /api/v5/tasks/{id}/comments

Получение комментариев к задаче

Для доступа к методу необходимо разрешение `task_read`.

Возвращает комментарии пользователей к задаче. По умолчанию ответ содержит до 20 записей.

Результат возвращается постранично. В поле `pagination` содержится информация о постраничной разбивке.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `id` | `integer` |     | ID задачи |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `comments[]` | `array of objects (TaskComment)` | Комментарий пользователя к задаче |
| `comments[][id]` | `integer` | ID комментария к задаче |
| `comments[][creator]` | `integer` | Автор комментария |
| `comments[][text]` | `string` | Текст комментария к задаче |
| `comments[][createdAt]` | `DateTime` | Дата создания |
| `comments[][updatedAt]` | `DateTime` | Дата изменения |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/tasks/{id}/edit

Редактирование задачи

Для доступа к методу необходимо разрешение `task_write`.

Метод позволяет вносить изменения в задачу

Если требуется изменить задачу, которая не была выполнена и время выполнения которой истекло, необходимо так же изменить и время выполнения задачи. Если при изменении такой задачи в поле `task[complete]` установлено значение `true`, время выполнения задачи изменять не требуется.

Если требуется изменить дату выполнения задачи, необходимо передать дату в формате `Y-m-d H:i` в поле `task[datetime]`. Дату необходимо указать в будущем времени.

В поле `task[performerId]` необходимо передать внутренний ID пользователя или группы, которой необходимо переназначить задачу.

Если требуется привязать задачу к существующему клиенту, то необходимо установить значение одного из следующих полей:

- `task[customer][id]` – внутренний ID клиента;
- `task[customer][externalId]` – внешний ID клиента.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск клиента будет осуществляться в рамках магазинов, к которым есть доступ у используемого API-ключа. Если клиент не будет найден, связь не будет установлена.

Если требуется привязать задачу к существующему заказу, то необходимо установить значение одного из следующих полей:

- `task[order][id]` – внутренний ID заказа;
- `task[order][externalId]` – внешний ID заказа;
- `task[order][number]` – номер заказа.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск заказа будет осуществляться в рамках магазинов, к которым есть доступ у используемого API-ключа. Если заказ не будет найден, связь не будет установлена.

При связывании задачи с существующим заказом, задача будет автоматически привязана к клиенту, к которому относится заказ.

При связывании задачи с клиентом/заказом, необходимо указать данные либо заказа, либо клиента.

При редактировании задачи на перезвон необходимо в поле `task[phone]` указать номер телефона, по которому следует перезвонить. В поле `task[phoneSite]` желательно указать символьный код магазина, чтобы иметь возможность осуществить перезвон с номера указанного магазина. Данная возможность зависит от используемой телефонии и её настроек.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `site` | `string` |     | Символьный код магазина |
| `task` | `object (SerializedTask)` |     |     |
| `task[text]` | `string` |     | Текст задачи |
| `task[commentary]` | `string` |     | Комментарий к задаче |
| `task[datetime]` | `DateTime` |     | Время выполнения задачи |
| `task[complete]` | `boolean` |     | Признак выполнения задачи |
| `task[phone]` | `string` |     | Телефон связанный с задачей |
| `task[customer]` | `object (SerializedEntityCustomer)` |     | Клиент, к которому привязана задача |
| `task[customer][id]` | `integer` |     | Внутренний ID клиента |
| `task[customer][externalId]` | `string` |     | Внешний ID клиента |
| `task[performerId]` | `integer` |     | Исполнитель задачи |
| `task[order]` | `object (SerializedEntityOrder)` |     | Заказ, к которому привязана задача |
| `task[order][id]` | `integer` |     | Внутренний ID заказа |
| `task[order][externalId]` | `string` |     | Внешний ID заказа |
| `task[order][number]` | `string` |     | Номер заказа |
| `task[phoneSite]` | `string` |     | Магазин, связанный с задачей на перезвон |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Задача успешно изменена</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при изменении задачи</p></li></ul></td></tr></tbody></table>

## Телефония

### POST /api/v5/telephony/call/event

События звонка

Для доступа к методу необходимо разрешение `telephony_write`.

Метод фиксирует события вызова для пользователей c добавочным кодами `codes` и/или c ID `userIds`, c телефона `phone`. Поле `codes` содержит JSON массив добавочных кодов, поле `userIds` содержит JSON массив ID пользователей.

Если одновременно указаны оба поля `codes` и `userIds`, событие вызова будет зафиксировано для всех перечисленных пользователей.

Поле `type` содержит тип события: _in_ - входящий вызов, _out_ - исходящий вызов, _hangup_ - завершение звонка.

В случае, если `type` равен ​_hangup_​, то в поле `hangupStatus` можно передать статус.

Поле `hangupStatus` содержит статус завершения звонка: _answered_ - звонок принят, _no answered_ - ответа на звонок не последовало, _busy_ - вызывающая сторона получает сигнал "занято", _cancel_ - звонок отменен, _failed_ - ошибка. По умолчанию значение _answered_.

Поле `campaign` содержит рекламную кампанию в рамках которой идет звонок. Содержит JSON, с полями: _name_ - название рекламной кампании и _code_ - код рекламной кампании. В случае если данное поле заполнено, в окне о входящем звонке будет указываться информация о рекламной кампании.

Событие звонка может быть связано с магазином. Для этого необходио указать символьный код магазина в поле `site`, или внешний номер магазина в поле `externalPhone`. Если указаны оба поля, они будут обработаны в следующем порядке:

- `site`
- `externalPhone`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `event` | `object (CallEvent)` |     |     |
| `event[phone]` | `string` | `{length: {max: 255}}{not blank}}` | Телефон |
| `event[type]` | `string` | `{not blank}[hangup\|in\|out]}` | Тип события |
| `event[codes][]` | `array of strings` |     | Добавочные коды менеджеров |
| `event[userIds][]` | `array of integers` |     | Массив ID пользователей |
| `event[site]` | `string` |     | Символьный код магазина, связанного с событием звонка |
| `event[hangupStatus]` | `string` | `[answered\|busy\|cancel\|failed\|no answered]` | Статус завершения звонка |
| `event[externalPhone]` | `string` | `{length: {max: 255}}` | Внешний номер телефона |
| `event[callExternalId]` | `string` | `{length: {max: 255}}` | External Id связанного c событием звонка |
| `event[webAnalyticsData]` | `object (SerializedWebAnalyticsData)` |     |     |
| `event[webAnalyticsData][campaign]` | `object (SerializedCampaign)` |     | Рекламная кампания |
| `event[webAnalyticsData][campaign][name]` | `string` |     | Название рекламной кампании |
| `event[webAnalyticsData][campaign][code]` | `string` |     | Код рекламной кампании |
| `event[webAnalyticsData][queryString]` | `string` |     | Поисковый запрос |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `notExistCodes` | `array` | Массив добавочных кодов, которые отсутствуют в системе |
| `notExistUsers` | `array` | Массив userId, которые отсутствуют в системе |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### POST /api/v5/telephony/calls/upload

Загрузка телефонных звонков

Для доступа к методу необходимо разрешение `telephony_write`.

Метод позволяет сохранять историю звонков. За один запрос можно отправить до 50 звонков.

Поле `calls` содержит JSON массив.

Поле `calls[][date]`, содержит дату время звонка в формате _Y-m-d H:i:s_.

Поле `calls[][type]`, может принимать следующие значения: _in_ - входящий звонок, _out_ - исходящий звонок.

Поле `calls[][result]`, может принимать следующие значения: _failed_ - ошибка , _answered_ - принят , _busy_ - занято, _no answer_ - не отвечено, _not allowed_ - запрещен , _unknown_ - неизвестно

Поле `calls[][externalId]` уникальный идентификатор звонка в АТС, если передать уже существующее значение звонок не будет создан.

Поле `calls[][recordUrl]` ссылка на запись звонка, например _http://example.com/record.mp3_. Поддерживаются записи в формате _.wav_, .mp3

Для сохранения звонка необходимо, чтобы было заполнено поле `calls[][userId]` или `calls[][code]`.

Поле `calls[][userId]` содержит ID пользователя, который обрабатывал звонок

Поле `calls[][code]` содержит внутренний номер пользователя, который обрабатывал звонок.

Поле _Длительность звонка_`calls[][duration]` должна быть больше, чем _Время ожидания ответа оператора_`calls[][durationWaiting]`. Если поле `calls[][duration]` не передано, в интерфейсе не будет отображаться плеер прослушивания записи звонка.

Если заданы оба поля, они будут обработаны в следующем порядке:

- `calls[][userId]`
- `calls[][code]`

Звонок может быть связан с магазином. Для этого необходимо указать символьный код магазина в поле `calls[][site]`, или внешний номер магазина в поле `calls[][externalPhone]`. Если указаны оба поля, они будут обработаны в следующем порядке:

- `calls[][site]`
- `calls[][externalPhone]`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `calls[]` | `array of objects (Call)` |     | Звонок |
| `calls[][date]` | `DateTime` | `{not blank}` | Дата/время звонка |
| `calls[][type]` | `string` | `[in\|out], {not blank}` | Тип звонка |
| `calls[][phone]` | `string` | `{not blank}{length: {max: 255}}}` | Номер телефона |
| `calls[][code]` | `string` | `{length: {max: 255}}` | Внутренний номер пользователя, который обрабатывал звонок |
| `calls[][userId]` | `integer` | `{range: {>=1, <=2147483647}}` | Id пользователя, который обрабатывал звонок |
| `calls[][result]` | `string` | `[answered\|busy\|failed\|no answer\|not allowed\|unknown], {not blank}` | Результат звонка |
| `calls[][duration]` | `integer` | `{range: {>=0, <=1000000000}}` | Длительность звонка (в секундах) |
| `calls[][durationWaiting]` | `integer` | `{range: {>=0, <=1000000000}}` | Время ожидания ответа оператора (в секундах) |
| `calls[][externalId]` | `string` | `{not blank}{length: {max: 255}}}` | ID звонка в АТС |
| `calls[][recordUrl]` | `string` | `{length: {max: 2048}}` | Ссылка на запись звонка |
| `calls[][source]` | `object (SerializedSource)` |     | Источник |
| `calls[][source][source]` | `string` |     | Источник (строка) |
| `calls[][source][medium]` | `string` |     | Канал |
| `calls[][source][campaign]` | `string` |     | Кампания |
| `calls[][source][keyword]` | `string` |     | Ключевое слово |
| `calls[][source][content]` | `string` |     | Содержание кампании |
| `calls[][externalPhone]` | `string` | `{length: {max: 255}}` | Внешний номер телефона |
| `calls[][site]` | `string` |     | Магазин |
| `calls[][clientId]` | `string` |     | Метка клиента Google Analytics |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `processedCallsCount` | `integer` | Количество успешно обработанных звонков |
| `duplicateCalls` | `array` | Массив externalId, которые уже присутствуют в системе |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Звонки успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/telephony/manager

Получение ответственного менеджера

Для доступа к методу необходимо разрешение `telephony_read`.

Метод возвращает ответственного менеджера, для клиента с телефоном `phone`, который в данный момент _Онлайн_ в системе и в статусе _Свободен_. Менеджер не будет возвращен, если для него не задан добавочный код в системе.

Если ответственный менеджер не найден, поле `manager` в ответе будет отсутствовать.

Если поле `ignoreStatus` равно `1`, в ответе будет присутствовать поле `manager` независимо от нахождения ответственного менеджера _Онлайн_ в системе и в статусе _Свободен_.

Если поле `details` равно `1`, в ответе будут присутствовать поля `links`, `customer`. В противном случае, поля будут отсутствовать.

Если клиент найден, поля `links[newCustomerLink]`, `links[newOrderLink]` в ответе будет отсутствовать.

Если клиент не найден, поля `customer`, `links[lastOrderLink]`, `links[customerLink]` в ответе будет отсутствовать.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `phone` | `string` | `{not blank}{length: {max: 255}}}` | Телефон |
| `details` | `string` | `[0\|1]` | Детальная информация |
| `ignoreStatus` | `string` | `[0\|1]` | Игнорировать статус менеджера |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `manager` | `object (SerializedManager)` | Менеджер |
| `manager[id]` | `string` | Id менеджера |
| `manager[firstName]` | `string` | Имя менеджера |
| `manager[lastName]` | `string` | Фамилия менеджера |
| `manager[patronymic]` | `string` | Отчество менеджера |
| `manager[email]` | `string` | Электронный адрес |
| `manager[code]` | `string` | Добавочный код менеджера в телефонии |
| `customer` | `object (SerializedCustomer)` | Клиент |
| `customer[id]` | `string` | Id клиента |
| `customer[externalId]` | `string` | Идентификатор с внешнего сайта |
| `customer[firstName]` | `string` | Имя клиента |
| `customer[lastName]` | `string` | Фамилия клиента |
| `customer[patronymic]` | `string` | Отчество клиента |
| `customer[email]` | `string` | Электронный адрес |
| `customer[phones][]` | `array of objects (CustomerPhone)` | Телефоны клиента |
| `customer[phones][][number]` | `string` | Номер телефона |
| `links` | `object (SerializedLinks)` | Ссылки |
| `links[newOrderLink]` | `string` | Ссылка на страницу создания нового заказа |
| `links[lastOrderLink]` | `string` | Ссылка на страницу последнего заказа |
| `links[newCustomerLink]` | `string` | Ссылка на страницу создания нового клиента |
| `links[customerLink]` | `string` | Ссылка на страницу клиента |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["telephony"\]\["changeUserStatusUrl"\]}

Оповещение о смене статуса

Если в настройках телефонии задано поле `changeUserStatusUrl` , при смене статуса менеджера в системе по заданному адресу будет отправлен `GET` запрос.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `code` | `string` | `{not blank}{length: {max: 255}}}` | Добавочный код менеджера |
| `userId` | `integer` | `{not blank}{length: {max: 255}}}` | Id пользователя |
| `clientId` | `string` | `{not blank}{length: {max: 255}}}` | Id клиента |
| `status` | `string` | `{not blank}[break\|busy\|dinner\|free]}` | Статус пользователя в системе |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["telephony"\]\["makeCallUrl"\]}

Инициация звонка

Если в настройках телефонии задано поле `makeCallUrl`, при инициации звонка по заданному адресу будет отправлен `GET` запрос. Ожидается код возврата `200`, в противном случае пользователю будет выведена ошибка.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `code` | `string` | `{not blank}{length: {max: 255}}}` | Добавочный код менеджера |
| `phone` | `string` | `{not blank}{length: {max: 255}}}` | Телефон |
| `clientId` | `string` | `{not blank}{length: {max: 255}}}` | Id клиента |
| `userId` | `integer` | `{not blank}{range: {>=1, <=2147483647}}}` | Id пользователя |
| `externalPhone` | `string` | `{length: {max: 255}}` | Внешний номер телефона |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr></tbody></table>

### CallbackPOST {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["telephony"\]\["personalAccountUrl"\]}

Переход в личный кабинет

Если задано поле `personalAccountUrl`, в карточке телефонии будет доступна кнопка «Личный кабинет телефонии», при нажатии на которую по заданному адресу будет отправлен `POST` запрос с параметром `clientId`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` | `{not blank}{length: {max: 255}}}` | Id клиента |

### CallbackGET {recordUrl}

Прослушивание звонка

Если у звонка задано поле `recordUrl`, при попытке прослушать звонок по заданному адресу будет отправлен `GET` запрос. Ожидается код возврата `200`, в противном случае пользователю будет выведена ошибка.

Для корректного воспроизведения аудиозаписи необходимо указывать верный заголовок `Content-Type`

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientId` | `string` | `{not blank}{length: {max: 255}}}` | Id клиента |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr></tbody></table>

## Транспорты

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["mgTransport"\]\["actions"\]\["online"\]}

Получение онлайн-статуса собеседника чата

Метод позволяет получить онлайн-статус собеседника чата. Для получения данных система инициирует GET запрос метода, указанного в `integrationModule["integrations"]["mgTransport"]["actions"]["online"]`. `clientId` связанного модуля интеграции передается в заголовке `X-Client-Id`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalUserId` | `string` |     | GET-параметр с внешним идентификатором клиента чата |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `lastOnline` | `DateTime` | Дата последнего онлайн-статуса пользователя |

### CallbackGET {integrationModule\["baseUrl"\]}/{integrationModule\["integrations"\]\["mgTransport"\]\["actions"\]\["visits"\]}

Получение данных визитов для указанного чата

Метод позволяет получить данные о визитах для указанного чата. Для получения данных система инициирует GET запрос метода, указанного в `integrationModule["integrations"]["mgTransport"]["actions"]["visits"]`. `clientId` связанного модуля интеграции передается в заголовке `X-Client-Id`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `externalChatId` | `string` |     | GET-параметр с внешним идентификатором чата |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `lastVisit` | `object (ChatLastVisit)` | Данные последнего посещения |
| `lastVisit[source]` | `string` | Источник визита |
| `lastVisit[createdAt]` | `DateTime` | Дата начала визита |
| `lastVisit[endedAt]` | `DateTime` | Дата окончания визита (текущая дата если визит не окончен) |
| `lastVisit[duration]` | `integer` | Продолжительность визита в секундах |
| `lastVisit[pages][]` | `array of objects (ChatVisitedPage)` | Посещенные в рамках визита страницы |
| `lastVisit[pages][][dateTime]` | `DateTime` | Дата и время посещения страницы |
| `lastVisit[pages][][url]` | `string` | URL страницы |
| `lastVisit[pages][][title]` | `string` | Заголовок страницы |
| `countVisits` | `custom handler result for (int)` | Количество посещений |
| `device` | `object (ChatDevice)` | Информация об устройстве пользователя |
| `device[lang]` | `string` | Язык на устройстве пользователя (в формате `en_US`) |
| `device[browser]` | `string` | Название и версия браузера |
| `device[os]` | `string` | Тип ОС пользователя |
| `utm` | `object (ChatUtm)` | UTM-метки пользователя |
| `utm[source]` | `string` | Значение метки `utm_source` |
| `utm[medium]` | `string` | Значение метки `utm_medium` |
| `utm[campaign]` | `string` | Значение метки `utm_campaign` |
| `country` | `string` | Страна пользователя |
| `city` | `string` | Город пользователя |

## Пользователи

### GET /api/v5/user-groups

Получение списка групп пользователей

Для доступа к методу необходимо разрешение `user_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `groups[]` | `array of objects (Group)` | Группа пользователей |
| `groups[][id]` | `integer` | ID группы |
| `groups[][name]` | `string` | Наименование |
| `groups[][signatureTemplate]` | `string` | Шаблон для подписи |
| `groups[][code]` | `string` | Код |
| `groups[][isManager]` | `boolean` | Обрабатывают заказы |
| `groups[][isDeliveryMen]` | `boolean` | Группа отвечает за доставку |
| `groups[][deliveryTypes]` | `array` | Типы доставок, за которые отвечает группа |
| `groups[][breakdownOrderTypes]` | `array` | Типы тех заказов, которые распределяются на менеджеров данной группы |
| `groups[][breakdownStatusGroups]` | `array` | Группы статусов тех заказов, которые распределяются на менеджеров данной группы |
| `groups[][breakdownSites]` | `array` | Магазины, заказов которых распределяются на менеджеров данной группы |
| `groups[][breakdownOrderMethods]` | `array` | Способы оформления тех заказов, которые распределяются на менеджеров данной группы |
| `groups[][grantedOrderTypes]` | `array` | Типы заказов, которые видны менеджерам данной группы, если доступ ограничен |
| `groups[][grantedStatusGroups]` | `array` | Группы статусов заказов, которые видны менеджерам данной группы, если доступ ограничен |
| `groups[][grantedSites]` | `array` | Магазины, заказы которых видны менеджерам данной группы |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/users

Получение списка пользователей, удовлетворяющих заданному фильтру

Для доступа к методу необходимо разрешение `user_read`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `limit` | `integer` | `{not blank}[20\|50\|100]}` | Количество элементов в ответе (по умолчанию равно 20) |
| `page` | `integer` | `{not blank}{range: {>=1}}}` | Номер страницы с результатами (по умолчанию равно 1) |
| `filter` | `object (ApiUserFilter)` |     |     |
| `filter[email]` | `string` | `{length: {max: 255}}` | Email пользователя |
| `filter[status]` | `string` | `[break\|busy\|dinner\|free]` | Статус пользователя в системе. При использовании фильтра `filter[status]` в выборку попадают только пользователи, у которых в поле `online` указано значение `true`. |
| `filter[online]` | `string` | `[0\|1]` | Пользователь онлайн |
| `filter[active]` | `string` | `[0\|1]` | Активность пользователя |
| `filter[isManager]` | `string` | `[1]` | Является менеджером |
| `filter[isAdmin]` | `string` | `[0\|1]` | Является администратором |
| `filter[groups][]` | `array` |     | Группы пользователя |
| `filter[createdAtFrom]` | `DateTime` |     | Дата создания пользователя (от) |
| `filter[createdAtTo]` | `DateTime` |     | Дата создания пользователя (до) |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `pagination` | `object (PaginationResponse)` | Постраничная разбивка |
| `pagination[limit]` | `integer` | Количество элементов в ответе |
| `pagination[totalCount]` | `integer` | Общее количество найденных элементов |
| `pagination[currentPage]` | `integer` | Текущая страница выдачи |
| `pagination[totalPageCount]` | `integer` | Общее количество страниц выдачи |
| `users[]` | `array of objects (SerializedUser)` | Информация о пользователе |
| `users[][id]` | `integer` | ID пользователя |
| `users[][createdAt]` | `DateTime` | Дата создания пользователя |
| `users[][active]` | `boolean` | Активность |
| `users[][email]` | `string` | Электронный адрес |
| `users[][firstName]` | `string` | Имя пользователя |
| `users[][lastName]` | `string` | Фамилия пользователя |
| `users[][patronymic]` | `string` | Отчество пользователя |
| `users[][position]` | `string` | Должность |
| `users[][photoUrl]` | `string` | URL фотографии |
| `users[][phone]` | `string` | Телефон |
| `users[][status]` | `string` | Статус пользователя в системе |
| `users[][online]` | `boolean` | Пользователь онлайн |
| `users[][isAdmin]` | `boolean` | Является администратором |
| `users[][isManager]` | `boolean` | Является менеджером |
| `users[][groups][]` | `array of objects (SerializedGroups)` | Группы пользователя |
| `users[][groups][][id]` | `integer` | ID группы |
| `users[][groups][][name]` | `string` | Название группы |
| `users[][groups][][code]` | `string` | Код группы |
| `users[][mgUserId]` | `integer` | ID MessageGateway пользователя |
| `users[][senderEmail]` | `string` | Адрес отправителя для менеджера |
| `users[][senderName]` | `string` | Имя отправителя |
| `users[][language]` | `string` | Язык интерфейса |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr></tbody></table>

### GET /api/v5/users/{id}

Получение информации о пользователе

Для доступа к методу необходимо разрешение `user_read`.

Получение информации о пользователе

Поле `user[status]` содержит статус пользователя в системе, может принимать следующие значения: _free_ - свободен; _busy_ - занят;_dinner_ - на обеде; _break_ - перерыв.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `id` | `integer` |     | ID пользователя |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `user` | `object (SerializedUser)` | Информация о пользователе |
| `user[id]` | `integer` | ID пользователя |
| `user[createdAt]` | `DateTime` | Дата создания пользователя |
| `user[active]` | `boolean` | Активность |
| `user[email]` | `string` | Электронный адрес |
| `user[firstName]` | `string` | Имя пользователя |
| `user[lastName]` | `string` | Фамилия пользователя |
| `user[patronymic]` | `string` | Отчество пользователя |
| `user[position]` | `string` | Должность |
| `user[photoUrl]` | `string` | URL фотографии |
| `user[phone]` | `string` | Телефон |
| `user[status]` | `string` | Статус пользователя в системе |
| `user[online]` | `boolean` | Пользователь онлайн |
| `user[isAdmin]` | `boolean` | Является администратором |
| `user[isManager]` | `boolean` | Является менеджером |
| `user[groups][]` | `array of objects (SerializedGroups)` | Группы пользователя |
| `user[groups][][id]` | `integer` | ID группы |
| `user[groups][][name]` | `string` | Название группы |
| `user[groups][][code]` | `string` | Код группы |
| `user[mgUserId]` | `integer` | ID MessageGateway пользователя |
| `user[senderEmail]` | `string` | Адрес отправителя для менеджера |
| `user[senderName]` | `string` | Имя отправителя |
| `user[language]` | `string` | Язык интерфейса |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пользователь не найден</p></li></ul></td></tr></tbody></table>

### POST /api/v5/users/{id}/status

Смена статуса пользователя

Для доступа к методу необходимо разрешение `user_write`.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `status` | `string` | `[free\|busy\|dinner\|break]` | Статус пользователя в системе. При использовании фильтра `filter[status]` в выборку попадают только пользователи, у которых в поле `online` указано значение `true`. |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка при выполнении запроса</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>404</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Пользователь не найден</p></li></ul></td></tr></tbody></table>

## Верификация

### POST /api/v5/verification/sms/confirm

Подтверждение верификации

Для доступа к методу необходимо разрешение `verification_write`.

Метод подтверждает верификацию

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `verification` | `object (SmsVerificationConfirm)` |     |     |
| `verification[code]` | `string` |     | Проверочный код |
| `verification[checkId]` | `string` |     | Идентификатор проверки кода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `verification` | `object (SmsVerification)` | SMS-верификация |
| `verification[createdAt]` | `DateTime` | Дата создания (Y-m-d H:i:s) |
| `verification[expiredAt]` | `DateTime` | Дата окончания срока жизни (Y-m-d H:i:s) |
| `verification[verifiedAt]` | `DateTime` | Дата успешной верификации (Y-m-d H:i:s) |
| `verification[checkId]` | `string` | Идентификатор проверки кода |
| `verification[actionType]` | `string` | Тип действия |

### GET /api/v5/verification/sms/{checkId}/status

Проверка статуса верификации

Для доступа к методу необходимо разрешение `verification_read`.

Метод получает статус текущего состояния верификации

Поле `actionType` возвращает тип действия для которого проводится подтверждение телефона. Возможные значения:

- `verify_loyalty_account` - подтверждение при активации участия в ПЛ;
- `verify_loyalty_account_phone` - подтверждение телефона участия в ПЛ;
- `confirm_loyalty_charge` - списание бонусов в ПЛ;

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `checkId` | `string` |     | Идентификатор проверки кода |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `verification` | `object (SmsVerification)` | SMS-верификация |
| `verification[createdAt]` | `DateTime` | Дата создания (Y-m-d H:i:s) |
| `verification[expiredAt]` | `DateTime` | Дата окончания срока жизни (Y-m-d H:i:s) |
| `verification[verifiedAt]` | `DateTime` | Дата успешной верификации (Y-m-d H:i:s) |
| `verification[checkId]` | `string` | Идентификатор проверки кода |
| `verification[actionType]` | `string` | Тип действия |

## Веб-аналитика

### POST /api/v5/web-analytics/client-ids/upload

Пакетная загрузка clientId веб-аналитики

Для доступа к методу необходимо разрешение `web_analytics_write`.

Метод позволяет загрузить до 100 меток clientId веб-аналитики. Поле `clientIds` содержит JSON-массив.

Значение clientId передается в обязательном поле `clientIds[][value]`.

Метка может быть связана с заказом или клиентом. Для загрузки в заказ используется поле `clientIds[][order]`. Для этого необходимо установить значение одного из следующих полей:  
<br/>`clientIds[order][id]` – внутренний ID заказа;  
`clientIds[order][externalId]` – внешний ID заказа;  
`clientIds[order][number]` – номер заказа.

Для загрузки в клиента используется одно из полей:  
<br/>`clientIds[customer][id]` – внутренний ID клиента;  
`clientIds[customer][externalId]` – внешний ID клиента;

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск по `externalId/number` будет осуществляться в рамках магазина, указанного в необязательном параметре `clientIds[][site]`.

При добавлении clientId в заказ он также будет добавлен в список clientId связанного клиента, если ещё не добавлен. При необходимости изменяются поля первого и последнего clientId клиента. Дату установки clientId можно указать в необязательном поле `clientIds[][createdAt]` (если оно не заполнено, будет использована текущая дата).

Поле `value`, а также любое из полей `order` и `customer` обязательны.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `clientIds[]` | `array of objects (ClientId)` | `{not blank}` | Массив clientId для загрузки |
| `clientIds[][value]` | `string` | `{not blank}{length: {min: 1, max: 255}}}` | Значение добавляемого clientId |
| `clientIds[][createdAt]` | `DateTime` |     | Дата добавления clientId |
| `clientIds[][order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `clientIds[][order][id]` | `integer` |     | Внутренний ID заказа |
| `clientIds[][order][externalId]` | `string` |     | Внешний ID заказа |
| `clientIds[][order][number]` | `string` |     | Номер заказа |
| `clientIds[][customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `clientIds[][customer][id]` | `integer` |     | Внутренний ID клиента |
| `clientIds[][customer][externalId]` | `string` |     | Внешний ID клиента |
| `clientIds[][site]` | `string` | `{length: {min: 1, max: 255}}` | Символьный код магазина, в котором ищется заказ или клиент |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `failedClientIds[]` | `array of objects (ClientId)` | Массив clientId для загрузки |
| `failedClientIds[][value]` | `string` | Значение добавляемого clientId |
| `failedClientIds[][createdAt]` | `DateTime` | Дата добавления clientId |
| `failedClientIds[][order]` | `object (SerializedEntityOrder)` | Заказ |
| `failedClientIds[][order][id]` | `integer` | Внутренний ID заказа |
| `failedClientIds[][order][externalId]` | `string` | Внешний ID заказа |
| `failedClientIds[][order][number]` | `string` | Номер заказа |
| `failedClientIds[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `failedClientIds[][customer][site]` | `string` | Символьный код магазина |
| `failedClientIds[][customer][id]` | `integer` | Внутренний ID клиента |
| `failedClientIds[][customer][externalId]` | `string` | Внешний ID клиента |
| `failedClientIds[][customer][type]` | `string` | Тип клиента |
| `failedClientIds[][site]` | `string` | Символьный код магазина, в котором ищется заказ или клиент |
| `errorMsg` | `string` |     |
| `errors` | `array` |     |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>clientId успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка загрузки clientId</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть clientId не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

### POST /api/v5/web-analytics/sources/upload

Пакетная загрузка источников

Для доступа к методу необходимо разрешение `web_analytics_write`.

Метод позволяет загрузить до 50 источников веб-аналитики. Поле `sources` содержит JSON-массив.

Данные источника передаются в полях `sources[][source]`, `sources[][medium]`, `sources[][campaign]`, `sources[][keyword]`, `sources[][content]`; должно быть передано как минимум одно из полей.

Источник может быть связан с заказом или клиентом. Для загрузки в заказ используется поле `sources[][order]`. Для этого необходимо установить значение одного из следующих полей:  
<br/>`source[order][id]` – внутренний ID заказа;  
`source[order][externalId]` – внешний ID заказа;  
`source[order][number]` – номер заказа.

Для загрузки в клиента используется одно из полей:  
<br/>`source[customer][id]` – внутренний ID клиента;  
`source[customer][externalId]` – внешний ID клиента;

Если при записи источника в заказ у клиента нет источника, он будет установлен в клиента.

Также может быть передано поле `sources[][clientId]` со значением clientId веб-аналитики: если он найден в системе, источник будет привязан к клиенту, связанному с этим clientId.

Если установлено значение нескольких полей, они будут обрабатываться в указанном выше порядке. Поиск по `externalId/number` будет осуществляться в рамках магазина, указанного в необязательном параметре `source[site]`.

Обязательно минимум одно из трёх полей `order`, `customer`, `clientId`, а также минимум одно из полей источника.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `sources[]` | `array of objects (Source)` | `{not blank}` | Массив источников для загрузки |
| `sources[][source]` | `string` |     | Источник |
| `sources[][medium]` | `string` |     | Канал |
| `sources[][campaign]` | `string` |     | Кампания |
| `sources[][keyword]` | `string` |     | Ключевое слово |
| `sources[][content]` | `string` |     | Содержание кампании |
| `sources[][clientId]` | `string` | `{length: {min: 1, max: 255}}` | clientId веб-аналитики, к которому будет привязан источник |
| `sources[][order]` | `object (SerializedEntityOrder)` |     | Заказ |
| `sources[][order][id]` | `integer` |     | Внутренний ID заказа |
| `sources[][order][externalId]` | `string` |     | Внешний ID заказа |
| `sources[][order][number]` | `string` |     | Номер заказа |
| `sources[][customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `sources[][customer][id]` | `integer` |     | Внутренний ID клиента |
| `sources[][customer][externalId]` | `string` |     | Внешний ID клиента |
| `sources[][site]` | `string` | `{length: {min: 1, max: 255}}` | Символьный код магазина, в котором ищется заказ или клиент |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `failedSources[]` | `array of objects (Source)` | Массив источников для загрузки |
| `failedSources[][source]` | `string` | Источник |
| `failedSources[][medium]` | `string` | Канал |
| `failedSources[][campaign]` | `string` | Кампания |
| `failedSources[][keyword]` | `string` | Ключевое слово |
| `failedSources[][content]` | `string` | Содержание кампании |
| `failedSources[][clientId]` | `string` | clientId веб-аналитики, к которому будет привязан источник |
| `failedSources[][order]` | `object (SerializedEntityOrder)` | Заказ |
| `failedSources[][order][id]` | `integer` | Внутренний ID заказа |
| `failedSources[][order][externalId]` | `string` | Внешний ID заказа |
| `failedSources[][order][number]` | `string` | Номер заказа |
| `failedSources[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `failedSources[][customer][site]` | `string` | Символьный код магазина |
| `failedSources[][customer][id]` | `integer` | Внутренний ID клиента |
| `failedSources[][customer][externalId]` | `string` | Внешний ID клиента |
| `failedSources[][customer][type]` | `string` | Тип клиента |
| `failedSources[][site]` | `string` | Символьный код магазина, в котором ищется заказ или клиент |
| `errorMsg` | `string` |     |
| `errors` | `array` |     |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Источники успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка загрузки источников</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть источников не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

### POST /api/v5/web-analytics/visits/upload

Пакетная загрузка визитов

Для доступа к методу необходимо разрешение `web_analytics_write`.

Метод позволяет загрузить до 50 визитов веб-аналитики. Поле `visits` содержит JSON-массив.

Визит связывается с клиентом. В визите для этого указывается одно из полей:  
<br/>`visit[customer][id]` – внутренний ID клиента;  
`visit[customer][externalId]` – внешний ID клиента;  
`visit[][clientId]` – значение clientId веб-аналитики: если он найден в системе, источник будет привязан к клиенту, связанному с этим clientId.

Каждый визит обязательно должен содержать поле `site`, которое указывает на магазин, связанный с визитом.

#### Параметры

| **Параметр** | **Тип** | **Формат** | **Описание** |
| --- | --- | --- | --- |
| `visits[]` | `array of objects (Visit)` | `{not blank}` | Массив визитов для загрузки |
| `visits[][createdAt]` | `string` | `{DateTime YYYY-MM-DD HH:MM:SS}` | Дата-время начала визита |
| `visits[][visitLength]` | `integer` |     | Длительность визита в секундах |
| `visits[][exitPage]` | `string` |     | Страница выхода |
| `visits[][landingPage]` | `string` |     | Страница входа |
| `visits[][pageViews]` | `integer` |     | Количество страниц, просмотренных в ходе визита |
| `visits[][pageDepth]` | `integer` |     | Глубина просмотра |
| `visits[][customer]` | `object (SerializedEntityCustomer)` |     | Клиент |
| `visits[][customer][id]` | `integer` |     | Внутренний ID клиента |
| `visits[][customer][externalId]` | `string` |     | Внешний ID клиента |
| `visits[][source]` | `object (SerializedSource)` |     | Данные по источнику клиента |
| `visits[][source][source]` | `string` |     | Источник |
| `visits[][source][medium]` | `string` |     | Канал |
| `visits[][source][campaign]` | `string` |     | Кампания |
| `visits[][source][keyword]` | `string` |     | Ключевое слово |
| `visits[][source][content]` | `string` |     | Содержание кампании |
| `visits[][pages][]` | `array of objects (Page)` |     | Массив страниц для загрузки |
| `visits[][pages][][url]` | `string` | `{not blank}` | URL страницы |
| `visits[][pages][][title]` | `string` |     | Заголовок страницы |
| `visits[][pages][][countViews]` | `integer` |     | Количество просмотров страницы |
| `visits[][pages][][timeOnPage]` | `integer` |     | Время, проведенное на странице в миллисекундах |
| `visits[][clientId]` | `string` | `{length: {min: 1, max: 255}}` | clientId веб-аналитики, к которому будет привязан визит |
| `visits[][site]` | `string` | `{length: {min: 1, max: 255}}` | Символьный код магазина, в котором ищутся страницы или клиент |

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |
| `failedVisits[]` | `array of objects (Visit)` | Массив визитов для загрузки |
| `failedVisits[][createdAt]` | `string` | Дата-время начала визита |
| `failedVisits[][visitLength]` | `integer` | Длительность визита в секундах |
| `failedVisits[][exitPage]` | `string` | Страница выхода |
| `failedVisits[][landingPage]` | `string` | Страница входа |
| `failedVisits[][pageViews]` | `integer` | Количество страниц, просмотренных в ходе визита |
| `failedVisits[][pageDepth]` | `integer` | Глубина просмотра |
| `failedVisits[][customer]` | `object (SerializedEntityCustomer)` | Клиент |
| `failedVisits[][customer][site]` | `string` | Символьный код магазина |
| `failedVisits[][customer][id]` | `integer` | Внутренний ID клиента |
| `failedVisits[][customer][externalId]` | `string` | Внешний ID клиента |
| `failedVisits[][customer][type]` | `string` | Тип клиента |
| `failedVisits[][source]` | `object (SerializedSource)` | Данные по источнику клиента |
| `failedVisits[][source][source]` | `string` | Источник |
| `failedVisits[][source][medium]` | `string` | Канал |
| `failedVisits[][source][campaign]` | `string` | Кампания |
| `failedVisits[][source][keyword]` | `string` | Ключевое слово |
| `failedVisits[][source][content]` | `string` | Содержание кампании |
| `failedVisits[][pages][]` | `array of objects (Page)` | Массив страниц для загрузки |
| `failedVisits[][pages][][url]` | `string` | URL страницы |
| `failedVisits[][pages][][title]` | `string` | Заголовок страницы |
| `failedVisits[][pages][][countViews]` | `integer` | Количество просмотров страницы |
| `failedVisits[][pages][][timeOnPage]` | `integer` | Время, проведенное на странице в миллисекундах |
| `failedVisits[][clientId]` | `string` | clientId веб-аналитики, к которому будет привязан визит |
| `failedVisits[][site]` | `string` | Символьный код магазина, в котором ищутся страницы или клиент |
| `errorMsg` | `string` |     |
| `errors` | `array` |     |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>201</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Визиты успешно загружены</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>400</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Ошибка загрузки визитов</p></li></ul></td></tr><tr><td colspan="1" rowspan="1"><p><code>460</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Во время загрузки возникли ошибки. Часть визитов не загружена (в ответе также присутствует массив ошибок "errors")</p></li></ul></td></tr></tbody></table>

## Статистика

### GET /api/v5/statistic/update

Обновление статистики

Для доступа к методу необходимо разрешение `analytics_write`.

Ставит в очередь задание на обновление ключевых статистических показателей в системе. Таймаут повторного вызова 60 сек. При более частых вызовах будет возвращаться 400 ошибка.

#### Ответ

| **Параметр** | **Тип** | **Описание** |
| --- | --- | --- |
| `success` | `boolean` | Результат запроса (успешный/неуспешный) |

#### Статусы ответа

<table style="min-width: 50px"><tbody><tr><th colspan="1" rowspan="1"><p><strong>Код статуса ответа</strong></p></th><th colspan="1" rowspan="1"><p><strong>Описание</strong></p></th></tr><tr><td colspan="1" rowspan="1"><p><code>200</code></p></td><td colspan="1" rowspan="1"><ul><li><p>Запрос успешно выполнен</p></li></ul></td></tr></tbody></table>

Документация сгенерирована 22.10.2025