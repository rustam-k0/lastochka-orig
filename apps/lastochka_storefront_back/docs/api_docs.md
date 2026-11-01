# API Documentation for E-Commerce Backend

> **Цель**: Минимизировать изменения на Flutter клиенте при миграции бэкенда.
> Документация основана на **доменном слое Flutter клиента**.

---

## Общие сведения

| Параметр | Значение |
|----------|----------|
| Формат данных | JSON |
| Кодировка | UTF-8 |
| DateTime | ISO 8601 (`"2024-01-15T10:30:00.000Z"`) |
| Денежные суммы | `int` в рублях (100 = 100₽) |
| Авторизация | `Authorization: Bearer <token>` |

---

## Enums

### Gender
```
male | female | other
```

### OrderStatus
```
pending | inDelivery | delivered | cancelled
```

---

## Модели данных

### DefaultPaginator<T>
Универсальная пагинация для списков.

```json
{
  "hasMore": true,
  "data": [...],
  "total": 100
}
```

| Поле | Тип | Описание |
|------|-----|----------|
| hasMore | bool | Есть ли ещё данные |
| data | T[]? | Массив данных |
| total | int? | Общее количество |

---

### Image
```json
{
  "id": 1,
  "path": "https://example.com/image.jpg"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| path | String | ✅ |

---

### User
```json
{
  "id": 1,
  "phone": "+79991234567",
  "firstName": "Иван",
  "lastName": "Иванов",
  "email": "ivan@example.com",
  "birthDate": "1990-01-15T00:00:00.000Z",
  "gender": "male"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| phone | String | ✅ |
| firstName | String? | ❌ |
| lastName | String? | ❌ |
| email | String? | ❌ |
| birthDate | DateTime? | ❌ |
| gender | Gender? | ❌ |

---

### LoginResponse
```json
{
  "token": "YOUR_ACCESS_TOKEN",
  "user": { ... }
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| token | String | ✅ |
| user | User | ✅ |

---

### Address
```json
{
  "id": 1,
  "userId": 1,
  "country": "Россия",
  "city": "Москва",
  "street": "Ленина",
  "houseNumber": "10",
  "isActive": true,
  "createdAt": "2024-01-15T10:30:00.000Z",
  "updatedAt": "2024-01-15T10:30:00.000Z",
  "entrance": "2",
  "floor": "5",
  "apartment": "42",
  "intercom": "42",
  "comment": "Домофон не работает"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| userId | int | ✅ |
| country | String | ✅ |
| city | String | ✅ |
| street | String | ✅ |
| houseNumber | String | ✅ |
| isActive | bool | ✅ |
| createdAt | DateTime | ✅ |
| updatedAt | DateTime | ✅ |
| entrance | String? | ❌ |
| floor | String? | ❌ |
| apartment | String? | ❌ |
| intercom | String? | ❌ |
| comment | String? | ❌ |

---

### WorkingHours
```json
{
  "openingHour": 9,
  "openingMinute": 0,
  "closingHour": 21,
  "closingMinute": 0
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| openingHour | int | ✅ |
| openingMinute | int | ✅ |
| closingHour | int | ✅ |
| closingMinute | int | ✅ |

---

### Store
```json
{
  "id": 1,
  "name": "Магазин №1",
  "minOrderAmount": 500,
  "workingHours": { ... },
  "isActive": true,
  "image": { ... },
  "rating": 4.5
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| name | String | ✅ |
| minOrderAmount | int | ✅ |
| workingHours | WorkingHours | ✅ |
| isActive | bool | ✅ |
| image | Image? | ❌ |
| rating | double? | ❌ |

---

### DeliverySlot
```json
{
  "id": 1,
  "timeSlot": "10:00-12:00"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| timeSlot | String | ✅ |

---

### Category
```json
{
  "id": 1,
  "name": "Молочные продукты",
  "slug": "dairy",
  "parent": null,
  "children": [...]
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| name | String | ✅ |
| slug | String? | ❌ |
| parent | Category? | ❌ |
| children | Category[]? | ❌ |

---

### ProductAttribute
```json
{
  "id": 1,
  "key": "Вес",
  "value": "500г",
  "sortOrder": 0
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| key | String | ✅ |
| value | String | ✅ |
| sortOrder | int | ✅ (default: 0) |

---

### Product
```json
{
  "id": 1,
  "sku": "SKU-001",
  "title": "Молоко 3.2%",
  "description": "Вкусное молоко",
  "price": 89,
  "priceOld": 99,
  "currency": "RUB",
  "images": [...],
  "isFavorite": false,
  "composition": "Молоко цельное",
  "proteins": 3.2,
  "fats": 3.2,
  "carbohydrates": 4.7,
  "calories": 59,
  "attributes": [...]
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| sku | String | ✅ |
| title | String? | ❌ |
| description | String? | ❌ |
| price | int? | ❌ |
| priceOld | int? | ❌ |
| currency | String? | ❌ |
| images | Image[]? | ❌ |
| isFavorite | bool | ✅ (default: false) |
| composition | String? | ❌ |
| proteins | double? | ❌ |
| fats | double? | ❌ |
| carbohydrates | double? | ❌ |
| calories | double? | ❌ |
| attributes | ProductAttribute[] | ✅ (default: []) |

---

### CartPromocode
```json
{
  "id": 1,
  "code": "PROMO10",
  "percent": 10
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| code | String | ✅ |
| percent | int | ✅ |

---

### CartItem
```json
{
  "id": 1,
  "product": { ... },
  "quantity": 2,
  "price": 89
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| product | Product | ✅ |
| quantity | int | ✅ |
| price | double | ✅ |

---

### CartStoreGroup
```json
{
  "id": 1,
  "store": { ... },
  "items": [...],
  "deliveryCost": 200,
  "discount": 50,
  "promocode": { ... }
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| store | Store | ✅ |
| items | CartItem[] | ✅ |
| deliveryCost | double | ✅ |
| discount | double | ✅ |
| promocode | CartPromocode? | ❌ |

---

### Cart
```json
{
  "storeGroups": [...]
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| storeGroups | CartStoreGroup[] | ✅ |

---

### PaymentCard
```json
{
  "id": 1,
  "last4": "4242",
  "isActive": true
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| last4 | String | ✅ |
| isActive | bool | ✅ |

---

### OrderAddress (snapshot)
```json
{
  "originalId": 1,
  "country": "Россия",
  "city": "Москва",
  "street": "Ленина",
  "houseNumber": "10",
  "floor": "5",
  "apartment": "42",
  "comment": null
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| originalId | int | ✅ |
| country | String | ✅ |
| city | String | ✅ |
| street | String | ✅ |
| houseNumber | String | ✅ |
| floor | String? | ❌ |
| apartment | String? | ❌ |
| comment | String? | ❌ |

---

### OrderPaymentCard (snapshot)
```json
{
  "originalId": 1,
  "last4": "4242",
  "cardType": "Visa"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| originalId | int | ✅ |
| last4 | String | ✅ |
| cardType | String | ✅ |

---

### OrderPromocode (snapshot)
```json
{
  "originalId": 1,
  "code": "PROMO10",
  "percent": 10
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| originalId | int | ✅ |
| code | String | ✅ |
| percent | int | ✅ |

---

### OrderItem (snapshot)
```json
{
  "id": 1,
  "productId": 10,
  "title": "Молоко 3.2%",
  "price": 89,
  "quantity": 2,
  "total": 178,
  "imageUrl": "https://..."
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| productId | int | ✅ |
| title | String | ✅ |
| price | double | ✅ |
| quantity | int | ✅ |
| total | double | ✅ |
| imageUrl | String? | ❌ |

---

### Order
```json
{
  "id": 1,
  "store": { ... },
  "status": "pending",
  "items": [...],
  "address": { ... },
  "paymentCard": { ... },
  "subtotal": 500,
  "discount": 50,
  "deliveryCost": 200,
  "total": 650,
  "itemsCount": 3,
  "deliverySlotTimeSlot": "10:00-12:00",
  "createdAt": "2024-01-15T10:30:00.000Z",
  "promocode": { ... },
  "comment": "Позвоните заранее"
}
```

| Поле | Тип | Обязательное |
|------|-----|--------------|
| id | int | ✅ |
| store | Store | ✅ |
| status | OrderStatus | ✅ |
| items | OrderItem[] | ✅ |
| address | OrderAddress | ✅ |
| paymentCard | OrderPaymentCard | ✅ |
| subtotal | double | ✅ |
| discount | double | ✅ |
| deliveryCost | double | ✅ |
| total | double | ✅ |
| itemsCount | int | ✅ |
| deliverySlotTimeSlot | String | ✅ |
| createdAt | DateTime | ✅ |
| promocode | OrderPromocode? | ❌ |
| comment | String? | ❌ |

---

## API Endpoints

---

## 1. AuthRepository

### `phoneLogin`
Запрос OTP кода.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| phone | String | ✅ |

**Ответ**: `String` — OTP код

---

### `phoneConfirm`
Подтверждение OTP.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| phone | String | ✅ |
| code | String | ✅ |

**Ответ**: `LoginResponse`

---

### `getProfile` [AUTH]
Получить профиль.

**Ответ**: `User`

---

### `updateProfile` [AUTH]
Обновить профиль.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| firstName | String? | ❌ |
| lastName | String? | ❌ |
| email | String? | ❌ |
| birthDate | DateTime? | ❌ |
| gender | Gender? | ❌ |

**Ответ**: `User`

---

### `logout` [AUTH]
Выход из системы.

**Ответ**: `void`

---

## 2. AddressRepository [AUTH]

### `getAddresses`
Получить все адреса.

**Ответ**: `Address[]`

---

### `getActiveAddress`
Получить активный адрес.

**Ответ**: `Address?`

---

### `createAddress`
Создать адрес.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| country | String | ✅ |
| city | String | ✅ |
| street | String | ✅ |
| houseNumber | String | ✅ |
| entrance | String? | ❌ |
| floor | String? | ❌ |
| apartment | String? | ❌ |
| intercom | String? | ❌ |
| comment | String? | ❌ |

**Ответ**: `Address`

---

### `updateAddress`
Обновить адрес.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| addressId | int | ✅ |
| country | String | ✅ |
| city | String | ✅ |
| street | String | ✅ |
| houseNumber | String | ✅ |
| entrance | String? | ❌ |
| floor | String? | ❌ |
| apartment | String? | ❌ |
| intercom | String? | ❌ |
| comment | String? | ❌ |

**Ответ**: `Address`

---

### `deleteAddress`
Удалить адрес.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| addressId | int | ✅ |

**Ответ**: `void`

---

### `setActiveAddress`
Установить активный адрес.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| addressId | int | ✅ |

**Ответ**: `Address`

---

## 3. StoreRepository

### `getStores`
Получить список магазинов.

| Параметр | Тип | Default |
|----------|-----|---------|
| page | int | 1 |
| perPage | int | 20 |

**Ответ**: `DefaultPaginator<Store>`

---

### `getStore`
Получить магазин.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |

**Ответ**: `Store?`

---

### `getDeliverySlots`
Получить слоты доставки.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |

**Ответ**: `DeliverySlot[]`

---

## 4. CategoryRepository

### `getRootCategories`
Получить корневые категории.

| Параметр | Тип | Default |
|----------|-----|---------|
| storeId | int | ✅ |
| includeChildren | bool | false |
| includeProducts | bool | дефолт форка |

**Ответ**: `DefaultPaginator<Category>`

`includeProducts=1` добавляет в каждую конечную категорию (без подкатегорий) до 8 товаров
в поле `products`. Работает только вместе с `includeChildren=1`.

Когда параметр не прислан, берётся дефолт форка: `catalog.category_tree.include_products_default`
(переменная `CATEGORY_TREE_INCLUDE_PRODUCTS_DEFAULT`, в коде `true` — товары отдаются).
Явный параметр в запросе всегда сильнее дефолта: `includeProducts=0` отключает товары даже
там, где дефолт `true`.

⚠️ Товары запрашивать только если экран действительно показывает их превью в категории.
До 10.09.2026 они отдавались всегда и составляли **96 % веса ответа**: на проде Ласточки
2 291 КБ из 2 376 КБ, 1 715 товаров в 233 листьях, при том что её клиент их не парсил.
Отключение дало **1,311 → 0,104 с** и **226 → 17 КБ** (в gzip). У Аладдина, наоборот,
меню ресторана рисуется из этого поля — там дефолт обязан оставаться `true`.

---

### `getChildCategories`
Получить дочерние категории.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |
| parentId | int | ✅ |

**Ответ**: `DefaultPaginator<Category>`

---

## 5. ProductRepository

### `getProduct`
Получить товар.

| Параметр | Тип | Default |
|----------|-----|---------|
| productId | int | ✅ |
| includeAttributes | bool | false |

**Ответ**: `Product?`

---

### `getProductsByCategory`
Получить товары категории.

| Параметр | Тип | Default |
|----------|-----|---------|
| storeId | int | ✅ |
| categoryId | int | ✅ |
| page | int | 1 |
| perPage | int | 20 |

**Ответ**: `DefaultPaginator<Product>`

---

### `getSimilarProducts`
Получить похожие товары.

| Параметр | Тип | Default |
|----------|-----|---------|
| productId | int | ✅ |
| page | int | 1 |
| perPage | int | 10 |

**Ответ**: `DefaultPaginator<Product>`

---

## 6. ProductGroupRepository

### `getProductGroups`
Получить товарные группы для главной.

| Параметр | Тип | Default |
|----------|-----|---------|
| placement | String | ✅ (`home`) |
| withProducts | bool | false |
| productsLimit | int | 4 (max 20) |

**Ответ**: `ProductGroup[]`

---

### `getStoreProductGroups`
Получить товарные группы мерчанта.

| Параметр | Тип | Default |
|----------|-----|---------|
| storeId | int | ✅ |
| placement | String | `all` (`store_page`, `catalog`, `all`) |
| withProducts | bool | false |
| productsLimit | int | 4 (max 20) |

**Ответ**: `ProductGroup[]`

---

### `getProductGroup`
Получить товарную группу.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| groupId | int | ✅ |

**Ответ**: `ProductGroup?`

---

### `getProductGroupProducts`
Получить товары группы.

| Параметр | Тип | Default |
|----------|-----|---------|
| groupId | int | ✅ |
| page | int | 1 |
| perPage | int | 20 (max 100) |

**Ответ**: `DefaultPaginator<Product>`

---

## 7. FavoriteRepository [AUTH]

### `getFavorites`
Получить избранное.

| Параметр | Тип | Default |
|----------|-----|---------|
| page | int | 1 |
| perPage | int | 20 |

**Ответ**: `DefaultPaginator<Product>`

---

### `toggleFavorite`
Переключить избранное.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| productId | int | ✅ |

**Ответ**: `Product`

---

## 8. CartRepository [AUTH]

### `getCart`
Получить корзину.

**Ответ**: `Cart`

---

### `addToCart`
Добавить в корзину.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| productId | int | ✅ |
| quantity | int | ✅ |

**Ответ**: `Cart`

---

### `updateQuantity`
Изменить количество.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| cartItemId | int | ✅ |
| quantity | int | ✅ |

**Ответ**: `Cart`

---

### `removeItem`
Удалить из корзины.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| cartItemId | int | ✅ |

**Ответ**: `Cart`

---

### `clearCart`
Очистить корзину.

**Ответ**: `void`

---

### `applyPromocode`
Применить промокод к магазину.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |
| promocode | String | ✅ |

**Ответ**: `Cart`

---

### `removePromocode`
Удалить промокод.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |

**Ответ**: `Cart`

---

### `clearStoreGroup`
Очистить группу магазина.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeGroupId | int | ✅ |

**Ответ**: `Cart`

---

## 9. PaymentCardRepository [AUTH]

### `getCards`
Получить карты.

**Ответ**: `PaymentCard[]`

---

### `addCard`
Добавить карту (mock).

**Ответ**: `PaymentCard`

---

### `setActiveCard`
Установить активную карту.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| cardId | int | ✅ |

**Ответ**: `void`

---

### `deleteCard`
Удалить карту.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| cardId | int | ✅ |

**Ответ**: `void`

---

## 10. OrderRepository [AUTH]

### `createOrder`
Создать заказ.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| storeId | int | ✅ |
| addressId | int | ✅ |
| paymentCardId | int | ✅ |
| deliverySlotId | int | ✅ |
| comment | String? | ❌ |

**Ответ**: `int` — ID заказа

---

### `getOrders`
Получить заказы.

| Параметр | Тип | Default |
|----------|-----|---------|
| page | int | 1 |
| perPage | int | 20 |

**Ответ**: `DefaultPaginator<Order>`

---

### `getOrderById`
Получить заказ.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| orderId | int | ✅ |

**Ответ**: `Order?`

---

### `cancelOrder`
Отменить заказ.

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| orderId | int | ✅ |

**Ответ**: `Order?`

---

### `checkSupplement`
Проверить возможность дополнения заказа.

**GET** `/api/v1/orders/check-supplement`

| Параметр | Тип | Обязательный |
|----------|-----|--------------|
| store_id | int | ✅ |

**Ответ**:
```json
{
  "order_id": 123,
  "can_supplement": true
}
```

Или если нет заказа для дополнения:
```json
{
  "order_id": null,
  "can_supplement": false
}
```

---

### `supplementOrder`
Дополнить заказ товарами из корзины.

**POST** `/api/v1/orders/{orderId}/supplement`

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| idempotency_key | String | ❌ | UUID для идемпотентности |

**Ответ**: `Order`

**Коды ошибок**:
| Код | Ситуация |
|-----|----------|
| 403 | Заказ не принадлежит пользователю |
| 409 | Заказ не в статусе `pending` |
| 422 | Корзина пуста / Недостаточно остатков |

---

## Примечания

1. **[AUTH]** — требуется токен в заголовке
2. **Пагинация** — page начинается с 1
3. **Snapshots** — при создании заказа данные копируются (address, card, items)
4. **Цены** — все в рублях как `int` или `double`
