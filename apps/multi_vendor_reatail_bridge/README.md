# RetailBridge

## Описание проекта

RetailBridge - это сервис-прослойка, обеспечивающий двусторонний обмен данными между RetailCRM и RabbitMQ. Сервис выступает посредником между событийной шиной и CRM, синхронизируя заказы, клиентов, товары, категории, сотрудников, магазины и другие сущности.

### Основные функции

1. **Приём событий из RabbitMQ**
   - Обрабатывает сообщения из очередей и конвертирует их в REST-запросы к RetailCRM API

2. **Отправка событий в RabbitMQ**
   - Принимает вебхуки из RetailCRM и публикует соответствующие события обратно в RabbitMQ

3. **Хранение маппингов**
   - Ведёт соответствие ID между RetailCRM и другими системами

## Архитектура

Проект построен на фреймворке Laravel и использует следующие компоненты:

- **EventHandlers** - обработчики событий из RabbitMQ
- **Converters** - конвертеры данных между форматами RetailCRM и RabbitMQ
- **RetailCRM Service** - сервис для работы с API RetailCRM
- **RabbitMQ Publisher/Consumer** - компоненты для работы с RabbitMQ
- **Mappings** - модели для хранения соответствий ID между системами

## Поддерживаемые сущности

1. **Товары (products)**
   - Создание, обновление, удаление, изменение остатков

2. **Категории (categories)**
   - Создание, обновление, удаление

3. **Заказы (orders)**
   - Создание, обновление, изменение статуса, отмена, оплата, возврат, обновление доставки

4. **Клиенты (customers)**
   - Создание, обновление, управление адресами

5. **Сотрудники (users)**
   - Создание, обновление, удаление

6. **Магазины (shops)**
   - Создание, обновление, удаление, изменение остатков

## Установка и настройка

### Требования

- PHP 8.0+
- Composer
- RabbitMQ Server
- MySQL/PostgreSQL
- RetailCRM API доступ

### Установка

1. Клонировать репозиторий:
   ```bash
   git clone https://github.com/your-repo/retail-bridge.git
   cd retail-bridge
   ```

2. Установить зависимости:
   ```bash
   composer install
   ```

3. Скопировать файл конфигурации:
   ```bash
   cp .env.example .env
   ```

4. Настроить подключение к базе данных и RabbitMQ в файле `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=retail_bridge
   DB_USERNAME=root
   DB_PASSWORD=

   RABBITMQ_HOST=localhost
   RABBITMQ_PORT=5672
   RABBITMQ_USER=guest
   RABBITMQ_PASSWORD=guest
   RABBITMQ_VHOST=/

   RETAILCRM_URL=https://your-retailcrm-instance.retailcrm.ru
   RETAILCRM_API_KEY=your-api-key
   ```

5. Сгенерировать ключ приложения:
   ```bash
   php artisan key:generate
   ```

6. Выполнить миграции:
   ```bash
   php artisan migrate
   ```

### Запуск обработчиков событий

Для запуска обработчиков событий из RabbitMQ используйте следующие команды:

```bash
# Обработка событий товаров
php artisan rabbitmq:consume-products

# Обработка событий категорий
php artisan rabbitmq:consume-categories

# Обработка событий заказов
php artisan rabbitmq:consume-orders

# Обработка событий клиентов
php artisan rabbitmq:consume-customers

# Обработка событий сотрудников
php artisan rabbitmq:consume-users

# Обработка событий магазинов
php artisan rabbitmq:consume-shops
```

Рекомендуется запускать эти команды через супервизор для обеспечения непрерывной работы.

### Настройка вебхуков RetailCRM

Для получения событий из RetailCRM необходимо настроить вебхуки в панели администратора RetailCRM:

1. Перейдите в раздел "Настройки" -> "Интеграции" -> "Вебхуки"
2. Добавьте новый вебхук с URL: `https://your-domain.com/api/webhooks/retailcrm`
3. Выберите необходимые типы событий для отправки

### Обработка ошибок и восстановление

Для работы с сообщениями, которые не удалось обработать (мертвая очередь):

```bash
# Просмотр сообщений в мертвой очереди для товаров
php artisan rabbitmq:process-dead-letters products

# Повторная обработка сообщений из мертвой очереди
php artisan rabbitmq:process-dead-letters products --retry
```

## Мультивендорность (shop_code)

RetailBridge поддерживает мультивендорность — одна инсталляция может обслуживать несколько магазинов (sites) в RetailCRM без изменения конфигурации.

### Как работает

Поле `shop_code` определяется динамически из входящих данных с fallback цепочкой:

1. **`$data['shop_code']`** — из входящего события RabbitMQ
2. **`config('retailcrm.shop_code')`** — из `.env` (`RETAILCRM_SHOP_CODE`)
3. **`'default'`** — hardcoded fallback

### Пример события с shop_code

```json
{
  "id": "ext-123",
  "source": "bagisto",
  "shop_code": "shop-almaty",
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": 1732720000,
  "client_info": {
    "name": "Иван",
    "surname": "Иванов",
    "phone": "+77001234567"
  },
  "items": [...]
}
```

### Конфигурация

```env
# Fallback shop_code, используется если в событии не указан shop_code
RETAILCRM_SHOP_CODE=default-shop

# Источник событий (для идентификации loopback)
RETAILCRM_SOURCE=retailcrm
```

### Идемпотентность

Уникальный индекс `(entity_type, source, external_id, shop_code)` гарантирует:
- Разные `shop_code` = разные сущности в метаданных
- Одна и та же сущность с разными `shop_code` обрабатывается независимо

### Обратная совместимость

- Существующие данные с `shop_code = NULL` продолжают работать
- Конфиг `RETAILCRM_SHOP_CODE` остаётся как fallback для legacy событий

## Мониторинг и логирование

Все события и ошибки логируются в стандартный лог Laravel. Для удобного мониторинга рекомендуется настроить отправку логов в систему централизованного логирования (например, ELK Stack).

## Расширение функциональности

Для добавления поддержки новых типов событий или сущностей:

1. Создайте новый обработчик событий в `app/Services/EventHandlers/`
2. Создайте новый конвертер в `app/Services/Converters/`
3. Добавьте новую модель маппинга в `app/Models/Mapping/`
4. Обновите конфигурацию RabbitMQ в `config/rabbitmq.php`
5. Создайте команду для консьюмера в `app/Console/Commands/RabbitMQ/`
6. Обновите обработчик вебхуков в `app/Http/Controllers/RetailCRM/WebhookController.php`

## Лицензия

Проект распространяется под лицензией MIT.
