# lastochka_admin_front

Фронтенд админ-панели курьерской системы и мультивендорного интернет-магазина Lastochka.

## Стек

- **Vue 3** (Composition API) + **JavaScript** (без TypeScript)
- **Vite 4** — сборщик
- **Pinia** — state management
- **Apollo Client** — GraphQL клиент
- **Stylus** + Rupture (responsive) + Nib (миксины) — стилизация
- **EditorJS** — редактор контента
- **Chart.js** + vue-chartjs — графики
- **Day.js** / Moment — работа с датами

## Команды

```bash
npm install --legacy-peer-deps   # установка зависимостей
npm run dev                       # dev сервер
npm run build                     # production сборка
npm run preview                   # preview production
npm run lint                      # ESLint проверка
```

## Структура проекта

```
src/
├── apollo.js          # Конфигурация 6 Apollo клиентов
├── App.vue            # Корневой компонент
├── main.js            # Точка входа
├── utils.js           # Утилиты
├── assets/            # Статика (изображения, шрифты)
├── components/        # Переиспользуемые компоненты
│   ├── inputs/        # 18+ input-компонентов
│   ├── table/         # Табличные компоненты
│   ├── modals/        # Модальные окна
│   ├── header/        # Шапка
│   └── UI/            # UI-компоненты
├── constants/         # Константы
├── graphql/           # GraphQL запросы и мутации
│   ├── schema.graphql # Полная схема
│   ├── queries/       # 52+ запроса
│   ├── mutations/     # 79+ мутаций
│   └── pages/         # GraphQL для страниц
├── router/            # Vue Router
│   └── parts/         # guard.js, auth.js
├── stores/            # Pinia stores (auth, default, pageFilters, settings)
├── styles/            # Stylus стили
│   ├── global/        # Глобальные
│   ├── parts/         # Части
│   ├── components/    # Компонентные
│   └── mixins/        # Миксины
└── views/             # 25 модулей страниц (161 .vue файл)
```

## GraphQL API — 6 endpoint'ов

Настроены в `src/apollo.js`, все через `VITE_HOST_ENDPOINT`:

| Клиент          | Путь                | Назначение                    |
|-----------------|---------------------|-------------------------------|
| defaultClient   | `/graphql-admin`    | Основная админ-панель         |
| landingClient   | `/graphql-landing`  | Landing-контент               |
| importerClient  | `/graphql-importer` | Импорт товаров из 1С          |
| purchaseClient  | `/graphql-purchase` | Покупки и слоты доставки      |
| deliveryClient  | `/graphql-delivery` | Доставка и курьеры            |
| loyalClient     | `/graphql-loyal-online` | Программа лояльности      |

Аутентификация — Bearer токен в заголовке `Authorization`, токены хранятся в cookies.

## Pinia Stores

- **auth** — JWT токены (access + refresh из cookies), данные пользователя
- **default** — модалы, endpoint'ы, глобальные константы, convertor_types
- **pageFilters** — сохранение фильтров между навигацией по страницам
- **settings** — глобальные настройки приложения

## Модули (views/)

| Модуль                | Описание                      |
|-----------------------|-------------------------------|
| orders                | Управление заказами           |
| products              | Управление товарами           |
| categories            | Категории товаров             |
| stores                | Магазины                      |
| clients               | Клиенты                       |
| staff                 | Сотрудники                    |
| campaigns             | Кампании                      |
| promocodes            | Промокоды                     |
| productGroups         | Группы товаров                |
| productReviews        | Отзывы о товарах              |
| merchantBanners       | Баннеры продавцов             |
| merchantPromocodes    | Промокоды продавцов           |
| merchantProductGroups | Группы товаров продавцов      |
| merchantSales         | Продажи продавцов             |
| merchantReviews       | Отзывы продавцов              |
| segments              | Сегменты клиентов             |
| stories               | Сториз                        |
| content               | Управление контентом сайта    |
| comments              | Комментарии                   |
| notifications         | Уведомления                   |
| desktop               | Главная страница админа       |
| auth                  | Аутентификация                |
| handler               | Обработчик ошибок             |

## ENV-переменные (.env.example)

```
PORT=                    # Порт dev-сервера
VITE_MEDIA_ENDPOINT=    # Endpoint для медиа-файлов (изображений)
VITE_HOST_ENDPOINT=     # Endpoint для GraphQL API
```

## Алиасы путей

- `@` → `src/`
- `~` → `node_modules/`

## Деплой

- **Dockerfile** — сборка + nginx для раздачи статики
- **docker-compose.yml** — локальная разработка
- **deployment.yaml** — Kubernetes deployment
- **nginx.conf** — конфиг nginx для production
- **.gitlab-ci.yml** — CI/CD через GitLab

## Ключевые компоненты

- `AsideComponent` — боковая навигация
- `TableComponent` — универсальная таблица с сортировкой
- `EditorJSComponent` — редактор контента (поддержка: code, header, list, table, image, quote, warning, embed, link)
- `ImageUpload` — загрузка + обрезка изображений (vue-cropperjs)
- `SelectComponent` — выпадающий список с поиском
- `ModalsComponent` — система модальных окон

## Тесты

Установлен `@vue/test-utils`, но тестовые файлы отсутствуют.
