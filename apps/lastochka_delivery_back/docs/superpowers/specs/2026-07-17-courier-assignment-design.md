# Назначение курьера сборщиком (модуль `courier_assignment`) — дизайн

Platrum: [12183](https://comrades.platrum.ru/tasks/task/12183) / MID-151.
Дата: 2026-07-17.

## Требование

1. По завершении сборки сборщик нажимает «Назначить курьера» и выбирает **одного**
   курьера из списка работающих сегодня сотрудников-курьеров.
2. Курьеры видят **только** назначенные им заказы. Весь список заказов курьер
   не видит.
3. Реализация — за фича-флагом (mendev — форк sync-core, код уходит во все форки,
   поведение меняется только там, где флаг включён).

## Контекст: что уже есть (флоу heavy_orders)

Механизм назначения курьеров сборщиком **уже реализован** в рамках модуля
`heavy_orders` и является платформенной семантикой «назначить курьера»
(идентична назначению из админки через REST `OrderController::delivery`):

- `mutation DeliveryAssignCouriers(order_id, courier_ids)` — takeOrder +
  автостарт рейса (или в хвост очереди, если рейс в пути) + push
  `order_assigned` курьеру + OrderHistory. Для не-heavy заказа — ровно один
  курьер, повторное назначение запрещено.
- `query shift_couriers` — курьеры с открытой сменой в магазине смены сборщика
  (= «работающие сегодня»; ability `collectorOnShift`).
- `query heavy_orders_enabled` — гейт мобильных экранов.
- Мобильное приложение сборщика уже имеет экран назначения курьеров
  (контракт `docs/specs/2026-07-07-heavy-order-collector-courier-api-contract.md`).

Чего нет: доступность назначения без модуля heavy_orders; фильтрация видимости
заказов у курьера; запрет самостоятельного взятия; отключение общего пуша
«заказ готов» всем курьерам смены.

## Рассмотренные подходы

- **A. Реюз DeliveryAssignCouriers + новый флаг `courier_assignment` (выбран).**
  Расширить гейт существующей мутации («heavy_orders ИЛИ courier_assignment»),
  добавить scope видимости для курьера, гейт self-take, скип общего пуша.
  Минимальный дифф, семантика назначения консистентна с админкой и heavy-флоу,
  мобильный экран назначения переиспользуется.
- **B. Отдельная мутация + поле `orders.assigned_courier_id`.** Чистое
  разделение, но дублирует механизм Delivery/DeliveryOrder, вводит новое
  состояние «назначен, но не взят» с новыми переходами и новыми экранами
  мобилки. Больше кода и риска при синхронизации на форки. Отклонён.
- **C. Просто включить heavy_orders.** Тащит признак «тяжёлый», пороги веса,
  мультикурьерство; не даёт фильтрацию видимости. Отклонён.

## Дизайн

Флаг: `courier_assignment` («Назначение курьера сборщиком») в
`project_modules` (admin_settings, общая БД mendev_djami). Default **OFF** во
всех форках — при выключенном флаге поведение всех сервисов байт-в-байт
текущее (fail-safe).

### delivery_back

1. **`Order::isCourierAssignmentModuleEnabled(): bool`** — зеркало
   `isHeavyOrdersModuleEnabled()` (прямое чтение
   `admin_settings.project_modules`, `['courier_assignment']['enabled'] ?? false`).
2. **`query courier_assignment_enabled: Boolean`** — резолвер
   `App\GraphQL\Queries\CourierAssignmentEnabled` (зеркало
   `HeavyOrdersEnabled`), схема рядом с `heavy_orders_enabled` в
   `graphql/Delivery/User/UserQueries.graphql`. Гейт мобильных экранов.
3. **`DeliveryAssignCouriersValidator`**: гейт меняется с «heavy_orders
   выключен → fail» на «оба модуля выключены → fail» с нейтральным текстом
   «Назначение курьеров отключено». Остальные правила не меняются: не-heavy
   заказ — ровно один курьер и запрет повторного назначения; статус
   ASSEMBLED; не самовывоз; только роль courier.
4. **`DeliveryAssignCouriers::__invoke`**: тот же расширенный гейт (сейчас
   `if (!Order::isHeavyOrdersModuleEnabled()) throw`).
5. **`Order::scopeByCourierAssignment`** — новый scope: при включённом
   `courier_assignment` И роли COURIER у auth-юзера показывает только заказы,
   назначенные текущему курьеру (`whereHas('deliveryOrders.delivery'
   [withoutGlobalScope NotFinished], user_id = auth)`); во всех остальных
   случаях no-op. Подключается в `orders_paginate` и `order(id)`
   (`graphql/Delivery/Order/OrderQueries.graphql`). Заказы в хвосте активного
   рейса (delivery_order WAIT_TO_START, заказ ещё ASSEMBLED) курьер видит —
   они назначены ему.
6. **`DeliveryTakeOrder::__invoke`**: при включённом флаге — UserError
   «Заказы назначает сборщик» (самостоятельное взятие отключено, в т.ч. для
   второго курьера heavy-заказа — при включённом courier_assignment всё идёт
   через назначение). Guard ставится ТОЛЬКО в `__invoke`: static `takeOrder()`
   обязан продолжать работать для REST-назначения из админки и для
   `DeliveryAssignCouriers` (см. delivery-back-pitfalls: REST зовёт static
   мимо `__invoke` — здесь это желаемое поведение, назначение админом
   остаётся доступным).
7. **`AssemblyFinish::notifyCouriersOrderReady`**: при включённом флаге — скип
   (ранний return). Пуш «заказ готов» всем курьерам смены не имеет смысла,
   когда курьеры не видят общий список; назначенному курьеру пуш
   `order_assigned` шлёт `DeliveryAssignCouriers`.
8. **`shift_couriers`** — без изменений кода; обновить docstring в схеме
   (экран назначения используется не только heavy-флоу).

### admin_back

9. **`AdminSettingQueries::getDefaultModulesConfig()`** + ключ
   `courier_assignment`: label «Назначение курьера сборщиком», enabled=false,
   description «Сборщик назначает курьера по завершении сборки; курьеры видят
   только назначенные им заказы, самостоятельное взятие заказов отключено».
   Merge-механика (`array_merge($defaults, $stored)`) сама доставит тумблер
   на стенды с уже сохранённым блобом. Никакой другой логики в admin_back
   не нужно: назначение/переназначение курьера из админки идёт через REST
   `delivery_back` и продолжает работать при любом состоянии флага.

### admin_front

Не трогаем: список модулей в «Настройки → Модули» рендерится из merged-конфига
бэкенда (прецедент heavy_orders — во фронтовый `MODULE_DEFAULTS` не добавлялся).

### Мобильное приложение (вне scope, контракт передаётся)

- Гейт экранов: `courier_assignment_enabled`.
- Сборщик: после `AssemblyFinish` показывать кнопку «Назначить курьера» для
  ВСЕХ заказов (не только heavy) → экран `shift_couriers` (выбор одного) →
  `DeliveryAssignCouriers(order_id, courier_ids: [<один id>])`.
- Курьер: вкладка общего списка скрывается/пустеет — бэкенд и так отдаёт
  только назначенные заказы; рейс работает как раньше.
- Контракт: `docs/specs/2026-07-17-courier-assignment-api-contract.md`
  (пишется в рамках этой фичи).

## Поведение и краевые случаи

- **Флаг OFF (все форки по умолчанию):** ни одна ветка нового кода не
  активируется; выдачи, мутации, пуши байт-в-байт как сейчас.
- **courier_assignment ON, heavy_orders OFF:** назначение доступно для всех
  заказов, ровно один курьер (is_heavy заморожен false — мультикурьерная
  ветка недостижима).
- **Оба ON:** heavy-заказы — несколько курьеров (существующее), обычные —
  один; self-take отключён для всех.
- **Сборщик не назначил курьера:** заказ остаётся ASSEMBLED и не виден
  курьерам; его видит админ в АП и назначает курьера существующим флоу
  (REST) — деградация управляемая. Дополнительно заказ виден сборщикам.
- **Переназначение:** сборщик повторно назначить не может («Заказ уже был
  взят в доставку») — переназначение остаётся за админкой (существующий флоу).
- **Самовывоз:** не назначается (существующая валидация), курьеру не виден
  (существующий excludePickup).
- **Split-заказы:** назначение только родителя (существующий запрет
  sub-order в takeOrder), курьер видит только родительские (byRole).
- **Ошибки чтения флага:** `?? false` + прямое чтение общей БД — при любом
  сбое ветка выключена, поведение старое (fail-safe default).

## Тесты

delivery_back (`tests/Feature`, DatabaseTransactions против mendev_test, по
паттернам DeliveryAssignCouriersTest):

1. `courier_assignment_enabled` — false по умолчанию / true при включённом.
2. Назначение одного курьера на обычный заказ при courier_assignment ON,
   heavy OFF → 200, delivery_order создан, заказ IN_DELIVERY, история
   ACTION_COURIER_ASSIGNED.
3. Оба флага OFF → «Назначение курьеров отключено» (обновление текста в
   существующем тесте гейта).
4. courier_assignment ON: два курьера на обычный заказ → ошибка (регрессия).
5. Scope: при флаге курьер видит в `orders_paginate` только назначенный ему
   заказ; чужие ASSEMBLED не видны; при выключенном флаге видит все (регрессия);
   сборщика фильтр не касается.
6. `DeliveryTakeOrder`: при флаге → «Заказы назначает сборщик»; без флага —
   работает (регрессия).
7. Пуш всем курьерам при AssemblyFinish: при флаге не шлётся, без флага
   шлётся (регрессия).

admin_back: `getDefaultModulesConfig` содержит `courier_assignment` с
enabled=false (+ merge доставляет ключ при сохранённом блобе).

## Аддендум (Platrum 12180): совместная доставка тяжёлых заказов в том же флаге

Требование: при включённом `courier_assignment` сборщик может назначить 2 и
более курьеров на тяжёлый заказ (`is_heavy`) БЕЗ включения модуля
heavy_orders. Семантика: «флоу совместной доставки тяжёлых» активен при
`heavy_orders` ИЛИ `courier_assignment` (эта дизъюнкция появляется во всех
местах, где heavy-функциональность назначения/признака гейтится модулем).
Прежний фикс stale is_heavy при этом ПЕРЕВОРАЧИВАЕТСЯ намеренно: при
courier_assignment ON мультикурьерность и донабор в пути для is_heavy
заказов — требуемое поведение, а не дыра; при обоих флагах OFF гейт мутации
по-прежнему всё запрещает.

Точки изменений:

- **delivery_back**: хелпер `Order::isHeavyDeliveryFlowEnabled()`
  (`isHeavyOrdersModuleEnabled() || isCourierAssignmentModuleEnabled()`);
  `DeliveryAssignCouriersValidator` (`$isHeavy = flow && is_heavy`);
  пересчёт `is_heavy` в `AssemblyFinish` (2 места). НЕ меняется:
  `DeliveryTakeOrderValidator::isJointDeliveryAllowed` — self-take при
  courier_assignment запрещён целиком guard'ом в `__invoke`, второй курьер
  героя не берёт заказ сам, его добавляет сборщик или админ.
- **storefront_back**: `ModuleConfigService::isCourierAssignmentEnabled()`
  (ключ `courier_assignment`, кеш, инвалидация); `HeavyOrderResolver::isHeavy`
  гейт «heavy ИЛИ courier_assignment» — чекаут-признак и плашки работают.
- **purchase_back**: `OrderCourierAddValidator` (доп. курьер из админки) —
  гейт «оба OFF → отказ» с текстом «Назначение курьеров отключено» (единый
  текст платформы).
- **admin_front**: колонка «Тяжёлый» в списке заказов, бейдж «Тяжёлый заказ»
  в шапке карточки заказа (open.vue), блок курьеров и кнопка «Добавить
  курьера» в aside карточки заказа, поле «Порог тяжёлого заказа» в карточке
  магазина — видимы при любом из двух модулей.
- **admin_back**: описание модуля courier_assignment дополняется упоминанием
  совместной доставки тяжёлых.
- **Контракт мобилке**: экран мультивыбора курьеров показывается при
  `(heavy_orders_enabled ИЛИ courier_assignment_enabled) && is_heavy`;
  донабор курьера в пути для тяжёлых доступен.

## Деплой / раскатка

- Ветки `feature/courier-assignment` в mendev_delivery_back и
  mendev_admin_back. Merge `--no-ff`, push, CI, включение флага и live-E2E на
  dev — только по явной команде.
- Раскатка на форки sync-core — по явной команде (флаг default OFF, безопасно).
- После деплоя admin_back: тумблер появляется в «Настройки → Модули»
  автоматически (merge дефолтов).
