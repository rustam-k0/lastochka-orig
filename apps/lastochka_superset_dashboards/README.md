# lastochka_superset_dashboards

Ассеты дашбордов Apache Superset «как код» для аналитики Lastochka: три дашборда
(«Аналитика - по магазину», 7 вкладок; «Аналитика - по сети», 3 вкладки;
«Операционные отчёты», 3 вкладки) собираются из декларативных YAML и импортируются
в Superset на dev и проде.

Собственный репозиторий разработчика дашбордов не используется: датасеты и чарты пишем
сами, сразу на реальной схеме БД.

## Пайплайн

```
локально: правим superset_assets/**/*.yaml
   -> git commit + git push (ветка main)
   -> на dev-сервере: git pull
   -> venv Superset: python scripts/import_assets.py   (импорт по uuid, идемпотентно)
   -> venv Superset: python scripts/run_dataset_sql.py  (валидация SQL датасетов)
```

На сервере файлы руками НЕ редактируем: любые правки идут через локальный
commit -> push -> pull. Импорт и валидация запускаются только в venv Superset на сервере.

## Структура

```
superset_assets/
  databases/       # 3 подключения (ссылки на СУЩЕСТВУЮЩИЕ по uuid, пароль из метабазы)
  datasets/
    lastochka_djami_copy/   # db1 MySQL - курьерка
    djami_store_copy/       # db2 MySQL - storefront
    lastochka_analytics/    # db3 PostgreSQL - журнал событий
  charts/          # чарты (dataset_uuid -> dataset, свой uuid)
  dashboards/      # 2 дашборда (сгенерированы build_dashboards.py)
scripts/
  import_assets.py       # in-process ImportAssetsCommand + fetch_metadata (только на сервере)
  run_dataset_sql.py     # SELECT * FROM (<sql>) t LIMIT 3 по каждому датасету (на сервере)
  chart_templates.py     # генераторы params 9 типовых чартов (справочник для задач вкладок)
  dashboards_manifest.py # манифест 2 дашбордов: вкладки -> список (chart_uuid, kind)
  build_dashboards.py    # генератор position_json из манифеста (GRID/TABS/ROW/CHART,
                          #   12-колоночная сетка + native_filter_configuration)
docs/
  conversion-rules.md  # 11 правил адаптации SQL под реальную схему (обязательны)
  metric-keys.md       # словарь metric_key (kpi_targets / shop_alert_rules)
```

Источник истины по составу виджетов и SQL: `../docs/analyticv2/ANALYTICS_BUILD_PLAN.md`
(BUILD_PLAN). Поправки к его допущениям: `../docs/analyticv2/OPEN_QUESTIONS_ANSWERS.md`.
План эпика: `../docs/superpowers/plans/2026-07-17-superset-dashboards-build.md`.

## Подключения Superset (dev, менять/пересоздавать нельзя)

| id | uuid | движок | БД |
|---|---|---|---|
| db1 | `5fe2883e-9e10-44ad-8cb5-22305f923603` | MySQL | `lastochka_djami_copy` (курьерка) |
| db2 | `22c13af4-950d-4550-9f16-1617f4df8d2f` | MySQL | `djami_store_copy` (storefront) |
| db3 | `43a9433a-6c4d-4350-98ba-c4ccf663080b` | PostgreSQL | `lastochka_analytics` (события) |
| db4 | `cf57c09a-89dc-4014-ad28-5dc33efa8cd9` | MySQL | `lastochka_djami` (курьерка, ЖИВАЯ база) |

db4 читает рабочую базу без суточной задержки и нужна только курьерским отчётам
«Операционных отчётов». MySQL-пользователь `superset_live`: SELECT на orders,
order_items, delivery_orders, deliveries, shops, shop_zones и на колонки users
(id, name, surname, patronymic, deleted_at). Пароль: прод `/home/admin/.prod-credentials`,
dev `/home/admin/apps/superset/.credentials` (строка `MYSQL superset_live: ...`).
В SQL Lab подключение скрыто. Первый импорт на новом сервере - с паролем через env:

```bash
SUPERSET_ASSET_PASSWORDS="{\"databases/lastochka_djami_live.yaml\": \"$(grep '^MYSQL superset_live:' <файл> | sed 's/^MYSQL superset_live: //')\"}" \
SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
  /home/admin/apps/superset/venv/bin/python scripts/import_assets.py
```

databases/*.yaml ссылаются на них по uuid. В `sqlalchemy_uri` пароль это маска
`XXXXXXXXXX` (стандарт Superset PASSWORD_MASK): импортёр видит существующее подключение по
uuid и подставляет реальный пароль из метабазы. Пароли в git не попадают.

## Запуск на сервере

```bash
# первичный клон
ssh admin@92.63.96.96 'cd /home/admin/apps && \
  git clone https://gitlab.comrades.dev/lastochka_djami/lastochka_superset_dashboards.git'

# последующие обновления + импорт + валидация
ssh admin@92.63.96.96 'cd /home/admin/apps/lastochka_superset_dashboards && git pull && \
  SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
    /home/admin/apps/superset/venv/bin/python scripts/import_assets.py'

ssh admin@92.63.96.96 'cd /home/admin/apps/lastochka_superset_dashboards && \
  SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
    /home/admin/apps/superset/venv/bin/python scripts/run_dataset_sql.py'
```

`run_dataset_sql.py [подстрока]` фильтрует датасеты по имени файла (удобно проверить
одну вкладку).

## Идемпотентность

`import_assets.py` использует `ImportAssetsCommand` c `overwrite=True`: матч по uuid,
повторный запуск обновляет объекты, дублей не создаёт. Поэтому uuid каждого объекта
генерируется один раз (uuid4) и НЕ меняется при последующих правках. При overwrite
Superset синхронизирует `columns`/`metrics` датасета под то, что в YAML: колонки, не
перечисленные в YAML, удаляются, затем `fetch_metadata()` до-обновляет их из SQL.

## Удаление объектов из Superset

Повторный импорт НЕ удаляет объект, которого больше нет в YAML (импортёр только
создаёт/обновляет). Чтобы убрать объект (например при переименовании датасета: uuid
меняется, старый остаётся сиротой), удалить его явно в метабазе Superset (venv, in-process):

```bash
SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
/home/admin/apps/superset/venv/bin/python - <<'PY'
from superset.app import create_app
app = create_app()
with app.app_context():
    from superset import db
    from superset.connectors.sqla.models import SqlaTable
    t = db.session.query(SqlaTable).filter_by(uuid="<uuid-датасета>").first()
    if t:
        db.session.delete(t)     # каскадно снимет columns/metrics
        db.session.commit()
        print("deleted", t.table_name)
    else:
        print("not found")
PY
```

Аналогично для чартов (`superset.models.slice.Slice`) и дашбордов
(`superset.models.dashboard.Dashboard`) по их uuid. Через UI: Datasets/Charts/Dashboards
-> строка -> Delete. Через CLI: `superset` не имеет точечного delete-by-uuid, поэтому
используем in-process скрипт выше.

## Операционные отчёты (20.10.2026)

Дашборд «Операционные отчёты» (`ops-reports`) по запросу заказчика: вкладки
«Товары» (потери, замены, ABC), «Курьеры» (производительность, нал/карта, зоны,
заработок), «Сборщики» (производительность, качество). Датасеты `ops_*`, чарты `Ops_*`.

- Фильтры заданы в манифесте (ключ `filters`): Период, Магазин, Товар, Курьер, Сборщик,
  Зона. Общие измерения во всех `ops_*` датасетах называются одинаково (`shop_name`,
  `product_name`, `courier_name`, `picker_name`, `zone_name`), поэтому один фильтр
  действует на все подходящие чарты.
- У каждого чарта adhoc-фильтр `TEMPORAL_RANGE` по временной колонке датасета:
  через него к чарту приходит «Период».
- `ops_abc_products` - Jinja-датасет (`get_time_filter`, `filter_values` с
  `remove_filter=True`): группы ABC считаются внутри датасета под выбранный период и
  магазин. Требует `FEATURE_FLAGS["ENABLE_TEMPLATE_PROCESSING"] = True` в
  `superset_config.py` (включено на dev и проде 20.10.2026). `run_dataset_sql.py` и
  `import_assets.py` рендерят такие датасеты в request-контексте.
- «Искали, нет в наличии» и «Запросы без результата» читают серверный лог поиска
  витрины `search_query_logs` (storefront_back, с 20.10.2026), итоговые запросы серии
  набора (`is_final = 1`). `ops_product_losses` связывает товар витрины с товаром
  курьерки через `event_sync_metadata` обеих копий.
- Тип ошибки сборки - `order_reviews.error_type`, проставляет оператор в админке.

## Правила разработки датасетов

Перед записью любого датасета в YAML прогнать SQL через чек-лист
`docs/conversion-rules.md` (11 правил). Ключевое: выручка через `status_id = 5` (не
`paid`), фильтр `parent_order_id IS NULL`, итог позиции `price_during_order * quantity`
(`oi.price` имеет смешанную семантику - НЕ использовать ни `SUM(oi.price)`, ни
`SUM(oi.price * oi.quantity)`), для реализованной выручки позиций дополнительно
`AND oi.status_id <> 3` (канонический фильтр реализованных позиций, симметрично на
COGS-ноге если она считается рядом - см. `docs/conversion-rules.md` правило 3), тип
доставки `type_id`, категории через pivot `category_product`, db3 использует `store_id`
и реальные `event_type`, db1/db2 это MySQL (без `EXTRACT(EPOCH)`/`date_trunc`/`FILTER`).
Никаких выдуманных полей: чего нет в реальной схеме и в conversion-rules, помечать skip.

## Статус (2026-10-20)
- «Операционные отчёты»: 6 датасетов, 26 чартов, 1 дашборд (3 вкладки), dev и прод.

## Статус (2026-07-17, dev)
- Построено: 43 датасета, 79 чартов, 2 дашборда («Аналитика - по магазину» 7 вкладок / 66 чартов; «Аналитика - по сети» 3 вкладки / 13 чартов).
- Пусто-ожидаемо до внешних данных: маржа/COGS (ждёт cost_price из 1С), b2c-воронки (ждут релиза мобилки с аналитикой), события таймингов сборки/доставки (B2E-журнал копится с 2026-07-15).
- Skip (нет источников): CRM/push (~10 виджетов), мобильная телеметрия, маркетплейс-KPI, WMS/остатки/свежесть, CAC/LTV-CAC.
- Демо-данные: правило расходов (аренда 50000), планы kpi_targets (avg_check 1000, revenue 30000), 2 правила сигналов — лента и светофор живые.
