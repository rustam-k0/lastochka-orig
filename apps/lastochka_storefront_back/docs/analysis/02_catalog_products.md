# Анализ: Каталог и Товары

## Экраны дизайна
- **ГЛАВНАЯ** — список категорий, баннеры, популярные товары
- **КАТАЛОГ ПАРТНЕРА** — категории магазина, список товаров
- **КАРТОЧКА ТОВАРА** — детальная информация о товаре
- **ИЗБРАННОЕ** — список избранных товаров

---

## Сопоставление: Категории

| Поле | API (Category) | Exchange (categories) | GAP |
|------|----------------|----------------------|-----|
| id | ✅ int | ✅ string | Разные типы |
| name | ✅ | ✅ title | Маппинг name↔title |
| slug | ✅ | ❌ | **Генерировать на бэке** |
| parent | ✅ Category | ✅ parent_id + parent_source | Нужна связь |
| children | ✅ Category[] | ❌ | Строится на бэке |
| image | ❌ | ✅ image_url | **Добавить в API** |
| active | ❌ | ✅ | Фильтрация на бэке |
| shop_code | ❌ | ✅ | Связь с магазином |
| meta_* | ❌ | ✅ | SEO поля (для веба) |

### ❓ Вопрос: Изображение категории
**Проблема:** Exchange передаёт `image_url`, но API Category не имеет поля image.
- В дизайне категории отображаются с иконками?
- Рекомендация: добавить `image` в модель Category.

---

## Сопоставление: Товары

| Поле | API (Product) | Exchange (products) | Дизайн | GAP |
|------|---------------|---------------------|--------|-----|
| id | ✅ int | ✅ string | — | Разные типы |
| sku | ✅ | ✅ | — | — |
| title | ✅ | ✅ | ✅ | — |
| description | ✅ | ✅ | ✅ | — |
| price | ✅ int (рубли) | ✅ number | ✅ | — |
| priceOld | ✅ | ✅ discount_price | ✅ | **Инверсия логики!** |
| currency | ✅ | ❌ | — | Хардкод RUB |
| images | ✅ Image[] | ❌ image_url (одна) | ✅ | **🔴 КРИТИЧНО** |
| isFavorite | ✅ | ❌ | ✅ | Локальная логика |
| composition | ✅ | ✅ via attributes | ✅ | Парсить из attributes |
| proteins | ✅ | ✅ via attributes | ✅ | Парсить из attributes |
| fats | ✅ | ✅ via attributes | ✅ | Парсить из attributes |
| carbohydrates | ✅ | ✅ via attributes | ✅ | Парсить из attributes |
| calories | ✅ | ✅ via attributes | ✅ | Парсить из attributes |
| attributes | ✅ ProductAttribute[] | ✅ attributes[] | ✅ | Разная структура |
| quantity | ❌ | ✅ | ❓ | Остатки |
| unit_type | ❌ | ✅ kg/piece | ❓ | Единица измерения |
| barcodes | ❌ | ✅ | ❌ | Для сканера |
| manufacturer | ❌ | ✅ | ❓ | Производитель |
| country | ❌ | ✅ | ❓ | Страна |
| brand | ❌ | ✅ | ❓ | Бренд |
| weight | ❌ | ✅ | ❓ | Вес в граммах |
| nds | ❌ | ✅ | ❌ | НДС |
| category_id | ❌ | ✅ | — | Связь |

---

## 🔴 Критические проблемы

### 1. Пищевая ценность (КБЖУ) — ✅ РЕШЕНО
**Источник:** Данные КБЖУ передаются через `attributes[]` в Exchange.

**Маппинг при импорте:**
```php
// Ожидаемые code в attributes:
'proteins'      → Product.proteins (double)
'fats'          → Product.fats (double)
'carbohydrates' → Product.carbohydrates (double)
'calories'      → Product.calories (double)
'composition'   → Product.composition (string)
```

**Вопрос:** Какие именно `code` используются в CRM для КБЖУ? Нужно уточнить точные названия.

### 2. Множественные изображения
**Проблема:** API требует массив `images[]`, Exchange передаёт только одну `image_url`.

**Вопросы:**
- Будет ли у товара несколько фото?
- Как передавать галерею?

**Рекомендация:** Расширить Exchange:
```json
"images": [
  {"url": "...", "sort_order": 0},
  {"url": "...", "sort_order": 1}
]
```

### 3. Цена со скидкой — инверсия логики
**Проблема:** 
- API: `price` = текущая цена, `priceOld` = старая (зачёркнутая)
- Exchange: `price` = базовая цена, `discount_price` = цена со скидкой

**Рекомендация:** Маппинг при синхронизации:
```
if (discount_price exists && discount_price < price):
    API.price = Exchange.discount_price
    API.priceOld = Exchange.price
else:
    API.price = Exchange.price
    API.priceOld = null
```

---

## Сопоставление: Атрибуты товара

| API (ProductAttribute) | Exchange (attributes[]) |
|------------------------|------------------------|
| id: int | ❌ |
| key: string | name: string |
| value: string | value: string/number |
| sortOrder: int | ❌ |
| — | code: string |

**Рекомендация:** Генерировать `id` и `sortOrder` на бэке.

---

## Недостающие поля в API

Для полноценной работы с Exchange нужно добавить в Product:

| Поле | Тип | Назначение |
|------|-----|------------|
| external_id | string | ID из внешней системы |
| source | string | Канал создания |
| shop_code | string | Код магазина |
| quantity | int | Остаток |
| unit_type | enum | kg/piece |
| manufacturer | string | Производитель |
| country | string | Страна |
| brand | string | Бренд |
| weight | int | Вес в граммах |
| category_id | int | Связь с категорией |

---

## Избранное

| Функционал | API | Exchange | Статус |
|------------|-----|----------|--------|
| Получить список | ✅ getFavorites | ❌ | Локальная логика |
| Добавить/убрать | ✅ toggleFavorite | ❌ | Локальная логика |

**✅ Статус:** Полностью локальная логика, не требует Exchange.

---

## События RabbitMQ для товаров

| Событие | Действие на бэке |
|---------|------------------|
| `product.created` | Создать товар в БД |
| `product.updated` | Обновить товар |
| `product.deleted` | Soft delete товара |
| `product.stock.changed` | Обновить quantity |
| `category.created` | Создать категорию |
| `category.updated` | Обновить категорию |
| `category.deleted` | Soft delete категории |

---

## Статус покрытия

| Функционал | API | Exchange | Готовность |
|------------|-----|----------|------------|
| Список категорий | ✅ | ✅ | 🟡 Нужен маппинг |
| Дерево категорий | ✅ | ✅ parent_id | 🟡 Построить на бэке |
| Список товаров | ✅ | ✅ | 🟡 Нужен маппинг |
| Детали товара | ✅ | ⚠️ | 🔴 Нет КБЖУ |
| Галерея товара | ✅ | ❌ | 🔴 Только 1 фото |
| Похожие товары | ✅ | ❌ | 🟡 Логика на бэке |
| Избранное | ✅ | ❌ | 🟢 Локально |
