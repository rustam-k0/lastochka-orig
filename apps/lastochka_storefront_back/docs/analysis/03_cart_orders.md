# Анализ: Корзина и Заказы

## Экраны дизайна
- **КОРЗИНА** — список товаров, группировка по магазинам, промокоды
- **ОФОРМЛЕНИЕ ЗАКАЗА** — выбор адреса, слота доставки, оплаты

---

## Сопоставление: Корзина

### Структура корзины в API

```
Cart
└── storeGroups[] (CartStoreGroup)
    ├── store (Store)
    ├── items[] (CartItem)
    │   ├── product (Product)
    │   ├── quantity
    │   └── price
    ├── deliveryCost
    ├── discount
    └── promocode (CartPromocode)
```

### Особенности
- Корзина группируется по магазинам
- Каждый магазин имеет свой промокод
- Доставка и скидка считаются на уровне магазина

### Exchange
**Корзина НЕ участвует в обмене** — это локальная сущность бэкенда.

**✅ Статус:** Полностью локальная логика.

---

## Сопоставление: Заказы

| Поле | API (Order) | Exchange (orders) | GAP |
|------|-------------|-------------------|-----|
| id | ✅ int | ✅ string | Разные типы |
| store | ✅ Store | ✅ shop_code | Связь по коду |
| status | ✅ enum | ✅ enum | **Разные статусы!** |
| items | ✅ OrderItem[] | ✅ order_item[] | Разная структура |
| address | ✅ OrderAddress | ✅ address{} | Разная структура |
| paymentCard | ✅ OrderPaymentCard | ❌ | **Нет в Exchange** |
| subtotal | ✅ | ✅ items_subtotal | — |
| discount | ✅ | ✅ discount_total | — |
| deliveryCost | ✅ | ✅ shipping_total | — |
| total | ✅ | ✅ grand_total | — |
| itemsCount | ✅ | ❌ | Считать на бэке |
| deliverySlotTimeSlot | ✅ string | ✅ slot_start/end_date | Разный формат |
| createdAt | ✅ | ✅ | — |
| promocode | ✅ OrderPromocode | ❌ | **Нет в Exchange** |
| comment | ✅ | ❌ | **Нет в Exchange** |
| — | ❌ | ✅ client_id | Связь с клиентом |
| — | ❌ | ✅ is_paid | Статус оплаты |
| — | ❌ | ✅ payment_type | cash/card |
| — | ❌ | ✅ shipping_method | Метод доставки |
| — | ❌ | ✅ tracking_number | Трек-номер |

---

## 🔴 Критические проблемы

### 1. Статусы заказов — несоответствие

| API | Exchange | Маппинг |
|-----|----------|---------|
| pending | created | ✅ |
| pending | assembling | ❓ |
| pending | assembled | ❓ |
| inDelivery | in_delivery | ✅ |
| delivered | delivered | ✅ |
| cancelled | cancelled | ✅ |
| ❌ | returned | ❓ |

**Вопросы:**
- Нужны ли клиенту статусы `assembling`, `assembled`?
- Что делать со статусом `returned`?

**Рекомендация:** Расширить API enum или маппить на `pending`:
```
assembling → pending (с доп. флагом)
assembled → pending
returned → cancelled (или новый статус)
```

### 2. Промокоды — отсутствуют в Exchange
**Проблема:** API поддерживает промокоды, Exchange — нет.

**Вопросы:**
- Где хранятся промокоды?
- Как синхронизировать скидку по промокоду?

**Рекомендация:** Добавить в Exchange:
```json
"promocode": {
  "code": "PROMO10",
  "discount_amount": 100,
  "discount_percent": 10
}
```

### 3. Платёжные карты — отсутствуют в Exchange
**Проблема:** API хранит `OrderPaymentCard`, Exchange только `payment_type: cash|card`.

**Вопросы:**
- Нужна ли CRM информация о карте (last4, cardType)?
- Достаточно ли знать "онлайн оплата" или "наличные"?

**Рекомендация:** Если CRM не нужны детали карты — оставить как есть.

### 4. Комментарий к заказу
**Проблема:** API поддерживает `comment`, Exchange — нет.

**Рекомендация:** Добавить в Exchange поле `comment`.

---

## Сопоставление: Позиции заказа (OrderItem)

| API (OrderItem) | Exchange (order_item) | GAP |
|-----------------|----------------------|-----|
| id | ✅ id | — |
| productId | ✅ product_id | — |
| title | ✅ product_name | Маппинг |
| price | ✅ unit_price | — |
| quantity | ✅ quantity | — |
| total | ✅ line_total | — |
| imageUrl | ❌ | **Нет в Exchange** |
| — | ✅ unit_price_effective | Цена со скидкой |
| — | ✅ line_subtotal | Подытог |
| — | ✅ line_discount_total | Скидка на позицию |

**Вопрос:** Откуда брать `imageUrl` для OrderItem?
- Рекомендация: копировать из Product при создании заказа.

---

## Сопоставление: Адрес заказа

| API (OrderAddress) | Exchange (address) | GAP |
|--------------------|--------------------|-----|
| originalId | ❌ | Локальный ID |
| country | ❌ | **Нет в Exchange** |
| city | ❌ | **Нет в Exchange** |
| street | ❌ | **Нет в Exchange** |
| houseNumber | ❌ | **Нет в Exchange** |
| floor | ✅ floor | — |
| apartment | ✅ flat | Маппинг apartment↔flat |
| comment | ❌ | Отдельно? |
| — | ✅ address (полный) | Одна строка |
| — | ✅ entrance | Подъезд |
| — | ✅ intercom | Домофон |
| — | ✅ latitude | Координаты |
| — | ✅ longitude | Координаты |

**Проблема:** API хранит адрес раздельно (country, city, street...), Exchange — одной строкой.

**Рекомендации:**
1. При отправке в Exchange: конкатенировать поля в `address`
2. При получении: парсить или хранить как есть
3. Добавить в Exchange поля `entrance`, `intercom` если нужны

---

## События RabbitMQ для заказов

| Событие | Триггер | Действие |
|---------|---------|----------|
| `order.created` | Клиент оформил заказ | Отправить в CRM |
| `order.status.changed` | CRM изменила статус | Обновить в БД |
| `order.cancelled` | Клиент/менеджер отменил | Синхронизировать |
| `order.paid` | Успешная оплата | Обновить is_paid |
| `order.delivery.updated` | Добавлен трек-номер | Обновить в БД |

---

## Создание заказа — флоу

```
1. Клиент нажимает "Оформить заказ"
2. API: createOrder(storeId, addressId, paymentCardId, deliverySlotId, comment)
3. Бэкенд:
   a. Валидация корзины
   b. Проверка остатков (опционально)
   c. Создание Order + OrderItems (snapshot)
   d. Очистка корзины магазина
   e. Отправка order.created в RabbitMQ
4. Возврат: orderId
```

---

## Статус покрытия

| Функционал | API | Exchange | Готовность |
|------------|-----|----------|------------|
| Получить корзину | ✅ | ❌ | 🟢 Локально |
| Добавить в корзину | ✅ | ❌ | 🟢 Локально |
| Применить промокод | ✅ | ❌ | 🟡 Где хранить? |
| Создать заказ | ✅ | ✅ | 🟡 Нужен маппинг |
| Список заказов | ✅ | ✅ | 🟡 Нужен маппинг |
| Детали заказа | ✅ | ✅ | 🟡 Нужен маппинг |
| Отмена заказа | ✅ | ✅ | 🟢 Готово |
| Статусы заказа | ⚠️ | ✅ | 🔴 Разные enum |
