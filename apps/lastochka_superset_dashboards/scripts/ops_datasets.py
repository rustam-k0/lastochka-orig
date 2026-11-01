#!/usr/bin/env python
"""Датасеты «Операционных отчётов» (ТЗ Магомеда v2.1 от 21.10.2026) как код.

Генерирует superset_assets/datasets/<db>/ops_*.yaml. YAML руками не править:
правка здесь -> python scripts/ops_datasets.py -> commit.

Запуск:
    python scripts/ops_datasets.py                      # записать все YAML
    python scripts/ops_datasets.py --render ops_x \\
        --from 2026-09-21 --to 2026-10-22 [--shop "Ласточка Джами"]
                                                       # SQL с подставленным периодом
                                                       # (проверка на копиях БД)

Архитектура (решение пользователя 21.10.2026): DWH, SSO, SCD2 и RLS из ТЗ не
делаем. Витрины ТЗ (dm_*) = virtual datasets поверх копий (db1/db2, обновление
раз в сутки) и живой курьерки (db4). Где отчёту нужен расчёт «на период»
(перцентили, ABC, RFM на дату среза, переходы между срезами, проверки качества),
период и магазин забираются в SQL через Jinja (ENABLE_TEMPLATE_PROCESSING):
get_time_filter / filter_values. У чарта такого датасета ОБЯЗАН быть adhoc
TEMPORAL_RANGE по main_dttm_col, иначе get_time_filter периода не увидит.

Общие правила (docs/conversion-rules.md + аудит 20.10.2026):
  * заказ = orders.parent_order_id IS NULL; доставлен = status_id 5, отменён = 6;
  * позиция = price_during_order * quantity; отменённая позиция status_id = 3;
  * позиции сборки - строки подзаказов из assemblies (копии на родителе
    задваивают замены);
  * тестовые сотрудники (в имени «тест») и тестовый клиент курьерки (id 35),
    тестовые клиенты витрины (21, 26, 27) исключены;
  * shop_name во всех датасетах = название магазина (общий фильтр «Магазин»).
"""
from __future__ import annotations

import argparse
import pathlib
import sys
import uuid

import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
DATASETS_DIR = ROOT / "superset_assets" / "datasets"

DB1 = "5fe2883e-9e10-44ad-8cb5-22305f923603"  # lastochka_djami_copy (курьерка, копия)
DB2 = "22c13af4-950d-4550-9f16-1617f4df8d2f"  # djami_store_copy (витрина, копия)
DB4 = "cf57c09a-89dc-4014-ad28-5dc33efa8cd9"  # lastochka_djami (курьерка, ЖИВАЯ)
DB_DIR = {DB1: "lastochka_djami_copy", DB2: "djami_store_copy", DB4: "lastochka_djami_live"}

# Новые датасеты получают детерминированный uuid5 от имени таблицы: перегенерация
# не плодит дублей в Superset.
NS = uuid.UUID("6a1b3f0e-2c4d-4e5f-8a9b-0c1d2e3f4a5b")


def ds_uuid(table: str) -> str:
    return str(uuid.uuid5(NS, f"ops-dataset:{table}"))


# uuid датасетов, созданных 20.10.2026: сохраняем, чтобы чарты и фильтры
# прод-дашборда не потеряли привязку.
KEEP_UUID = {
    "ops_courier_orders": "36a9cf7e-b5c0-4c80-a5be-79eaa4d1810a",
    "ops_picker_orders": "7f64de2b-ba11-4e40-a875-f5f51593a14d",
    "ops_assembly_items": "47fddec7-0198-47af-bebf-6193d5235a0d",
    "ops_product_losses": "1ee44de1-76c8-439c-bbe1-720914f5e3fc",
    "ops_abc_products": "0c964450-5adb-4c83-9968-c05efd2a5aed",
    "ops_search_queries": "e3f4b497-a07b-46b9-8b83-9291c7d55dab",
}

# ---------------------------------------------------------------------------
# Общие фрагменты SQL
# ---------------------------------------------------------------------------

NOT_TEST_CLIENT_COURIER = "35"
NOT_TEST_USERS_STORE = "(21, 26, 27)"


def person(alias: str, id_expr: str, fallback: str) -> str:
    """ФИО сотрудника «Фамилия Имя» или «<fallback> #id»."""
    return (
        f"COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {alias}.surname, {alias}.name)), ''), "
        f"CONCAT('{fallback} #', {id_expr}))"
    )


def not_test_staff(alias: str) -> str:
    return f"COALESCE(CONCAT_WS(' ', {alias}.surname, {alias}.name), '') NOT LIKE '%тест%'"


def root_category_join(product_expr: str, prefix: str = "cat") -> str:
    """Корневая категория товара курьерки (вложенность до 4 уровней)."""
    return f"""LEFT JOIN category_product {prefix}p ON {prefix}p.product_id = {product_expr}
  LEFT JOIN categories {prefix}1 ON {prefix}1.id = {prefix}p.category_id
  LEFT JOIN categories {prefix}2 ON {prefix}2.id = {prefix}1.parent_id
  LEFT JOIN categories {prefix}3 ON {prefix}3.id = {prefix}2.parent_id
  LEFT JOIN categories {prefix}4 ON {prefix}4.id = {prefix}3.parent_id"""


def root_category(prefix: str = "cat") -> str:
    return (
        f"COALESCE({prefix}4.title, {prefix}3.title, {prefix}2.title, {prefix}1.title, "
        f"'Без категории')"
    )


def period_where(col: str, var: str = "period") -> str:
    """Условие периода из Jinja-переменной get_time_filter (пусто = всё время)."""
    return (
        f"{{% if {var}.from_expr %}}AND {col} >= {{{{ {var}.from_expr }}}}{{% endif %}}\n"
        f"        {{% if {var}.to_expr %}}AND {col} < {{{{ {var}.to_expr }}}}{{% endif %}}"
    )


def shops_where(col: str, var: str = "shops") -> str:
    return f"{{% if {var} %}}AND {col} IN {{{{ {var} | where_in }}}}{{% endif %}}"


# Дата среза: конец периода (не включительно) или «сейчас», если период не задан.
ASOF_EXPR = "{% if period.to_expr %}{{ period.to_expr }}{% else %}NOW(){% endif %}"
FROM_EXPR = "{% if period.from_expr %}{{ period.from_expr }}{% else %}'2000-01-01'{% endif %}"


DATASETS: list[dict] = []


def dataset(**kw) -> dict:
    kw.setdefault("uuid", KEEP_UUID.get(kw["table_name"]) or ds_uuid(kw["table_name"]))
    DATASETS.append(kw)
    return kw


def col(name: str, verbose: str | None = None, dttm: bool = False) -> dict:
    out = {"column_name": name, "is_dttm": dttm}
    if verbose:
        out["verbose_name"] = verbose
    if not dttm:
        out["groupby"] = True
        out["filterable"] = True
    return out


# ===========================================================================
# Вкладка «Товары»
# ===========================================================================

# R2 «Частота замен» (+ знаменатели для R1). Строка = позиция заказа, прошедшего
# сборку (подзаказ сплита или заказ без сплита).
#   * Товар = запрошенный клиентом (origin_product_id при замене), замена = product_id.
#   * Причина замены - product_replace_reasons; мобилка сборщика её пока не шлёт,
#     поэтому почти всё «Причина не указана».
#   * Согласие клиента система не фиксирует. Есть только опция чекаута
#     «Позвонить, если товара нет» (call_if_no_exists): замена в таком заказе
#     считается «Согласовано» (клиент просил согласовать), без опции -
#     «Без согласования» (no_offer). Факт звонка не хранится.
#   * Δ цены = цена замены - цена запрошенного товара за единицу.
#   * cheaper_loss_amount = на сколько позиция подешевела из-за замены
#     (запрошенное origin_price * origin_quantity минус итог замены), только > 0.
#   * Отказ = позиция убрана без замены (status_id = 3) по любой причине,
#     refused_amount = её полная сумма.
#   * period_delivered_orders - доставленные заказы периода и магазина
#     (знаменатель «% всех заказов»), считается в SQL по Jinja-периоду.
dataset(
    table_name="ops_assembly_items",
    database_uuid=DB1,
    main_dttm_col="order_created_at",
    sql=f"""{{% set period = get_time_filter('order_created_at', target_type='DATETIME') %}}
{{% set shops = filter_values('shop_name') %}}
SELECT
    t.*,
    CASE WHEN t.is_replaced = 1 AND t.has_call_option = 1 THEN 1 ELSE 0 END AS is_agreed,
    CASE WHEN t.is_replaced = 1 AND t.has_call_option = 0 THEN 1 ELSE 0 END AS is_no_offer,
    CASE WHEN t.is_replaced = 1 AND t.is_order_delivered = 1 THEN t.order_id END AS replaced_delivered_order_id,
    pd.period_delivered_orders
FROM (
    SELECT
        oi.id                                           AS order_item_id,
        po.id                                           AS order_id,
        o.shop_id                                       AS shop_id,
        s.title                                         AS shop_name,
        o.created_at                                    AS order_created_at,
        a.user_id                                       AS picker_id,
        {person('u', 'a.user_id', 'Сборщик')}           AS picker_name,
        COALESCE(oi.origin_product_id, oi.product_id)   AS product_id,
        COALESCE(p.title, oi.origin_title, CONCAT('Товар #', COALESCE(oi.origin_product_id, oi.product_id))) AS product_name,
        {root_category()}                               AS category_name,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN 1 ELSE 0 END                          AS is_replaced,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN oi.product_id END                     AS replacement_product_id,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN COALESCE(pr.title, CONCAT('Товар #', oi.product_id)) END AS replacement_product_name,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN COALESCE(rr.title, 'Причина не указана') END AS replace_reason_name,
        CASE WHEN JSON_SEARCH(po.checkout_options, 'one', 'call_if_no_exists') IS NOT NULL
             THEN 1 ELSE 0 END                          AS has_call_option,
        CASE WHEN po.status_id = 5 THEN 1 ELSE 0 END    AS is_order_delivered,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN oi.price_during_order - oi.origin_price END AS price_delta,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN GREATEST(oi.origin_price * COALESCE(oi.origin_quantity, oi.quantity)
                           - oi.price_during_order * oi.quantity, 0)
             ELSE 0 END                                 AS cheaper_loss_amount,
        CASE WHEN oi.status_id = 3 THEN 1 ELSE 0 END    AS is_refused,
        CASE WHEN oi.status_id = 3 THEN oi.price_during_order * oi.quantity ELSE 0 END AS refused_amount,
        CASE WHEN oi.status_id = 3 AND COALESCE(oi.cancel_reason_id, 0) <> 5
             THEN 1 ELSE 0 END                          AS is_not_found,
        CASE WHEN oi.status_id = 3 THEN COALESCE(cr.title, 'Причина не указана') END AS cancel_reason_name,
        oi.price_during_order * oi.quantity             AS line_amount
    FROM order_items oi
    JOIN assemblies a ON a.order_id = oi.order_id AND a.deleted_at IS NULL
    JOIN orders o     ON o.id = oi.order_id
    JOIN orders po    ON po.id = COALESCE(o.parent_order_id, o.id)
    LEFT JOIN shops s ON s.id = o.shop_id
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN products p  ON p.id = COALESCE(oi.origin_product_id, oi.product_id)
    LEFT JOIN products pr ON pr.id = oi.product_id
    LEFT JOIN product_replace_reasons rr ON rr.id = oi.replace_reason_id
    LEFT JOIN product_cancel_reasons cr ON cr.id = oi.cancel_reason_id
    {root_category_join('COALESCE(oi.origin_product_id, oi.product_id)')}
    WHERE oi.deleted_at IS NULL
      AND o.deleted_at IS NULL
      AND COALESCE(po.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
      AND {not_test_staff('u')}
) t
CROSS JOIN (
    SELECT COUNT(*) AS period_delivered_orders
    FROM orders x
    LEFT JOIN shops xs ON xs.id = x.shop_id
    WHERE x.parent_order_id IS NULL
      AND x.deleted_at IS NULL
      AND x.status_id = 5
      AND COALESCE(x.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
      {period_where('x.created_at')}
      {shops_where('xs.title')}
) pd
""",
    columns=[
        col("order_created_at", dttm=True),
        col("shop_name", "Магазин"),
        col("category_name", "Категория"),
        col("product_name", "Товар"),
        col("replacement_product_name", "Замена"),
        col("replace_reason_name", "Причина замены"),
        col("picker_name", "Сборщик"),
    ],
)


# R1 «Потери на товарах». Строка = событие спроса или позиция сборки
# (loss_kind):
#   * «Товар не найден · оценка» - итоговый запрос серии поиска (лог
#     search_query_logs, is_final = 1): товар есть в каталоге магазина, но без
#     остатка. Первые 5 таких товаров по релевантности, по строке на товар.
#     Оценка: один запрос = одна несостоявшаяся покупка по текущей цене товара
#     витрины (история цен не хранится); если без остатка нашлось несколько
#     товаров, цена делится поровну между ними (сумма по запросу = средняя цена).
#   * «Запрос без привязки» - итоговый запрос с пустой выдачей: товара нет в
#     каталоге, привязать запрос не к чему (подтаб «Запросы без привязки»).
#   * «Замена дешевле» - позиция подешевела из-за замены (cheaper_loss_amount).
#   * «Отказ от замены» - позиция убрана без замены, полная сумма.
#   * «Замена» / «Собрано» - остальные позиции сборки (без потерь): нужны как
#     знаменатель доли замен по товару.
# Себестоимости нет (products.cost_price пуст) - упущенная маржа = NULL.
dataset(
    table_name="ops_product_losses",
    database_uuid=DB1,
    main_dttm_col="event_at",
    sql=f"""SELECT
    'Товар не найден · оценка'                      AS loss_kind,
    l.searched_at                                   AS event_at,
    COALESCE(sh.title, st.name)                     AS shop_name,
    {root_category()}                               AS category_name,
    COALESCE(cp.title, sp.title, CONCAT('Товар витрины #', j.sf_product_id)) AS product_name,
    NULL                                            AS picker_name,
    NULL                                            AS replace_reason_name,
    l.query_normalized                              AS search_query,
    l.id                                            AS search_id,
    l.actor_key                                     AS client_key,
    NULL                                            AS order_id,
    0                                               AS is_position,
    0                                               AS is_replaced,
    0                                               AS is_refused,
    1                                               AS is_search_unavailable,
    COALESCE(sp.price, 0) / LEAST(JSON_LENGTH(l.unavailable_product_ids), 5) AS search_amount,
    0                                               AS price_delta_amount,
    0                                               AS refused_amount,
    COALESCE(sp.price, 0) / LEAST(JSON_LENGTH(l.unavailable_product_ids), 5) AS loss_amount,
    CAST(NULL AS DECIMAL(12, 2))                    AS margin_lost
FROM djami_store_copy.search_query_logs l
CROSS JOIN JSON_TABLE(
    l.unavailable_product_ids, '$[*]' COLUMNS (
        relevance_rank FOR ORDINALITY,
        sf_product_id BIGINT PATH '$'
    )
) AS j
LEFT JOIN djami_store_copy.stores st ON st.id = l.store_id
LEFT JOIN shops sh ON sh.code = st.code
LEFT JOIN djami_store_copy.products sp ON sp.id = j.sf_product_id
LEFT JOIN djami_store_copy.event_sync_metadata sm
       ON sm.entity_type = 'products' AND sm.entity_id = j.sf_product_id
LEFT JOIN event_sync_metadata cm
       ON cm.entity_type = 'products' AND cm.source = sm.source AND cm.external_id = sm.external_id
LEFT JOIN products cp ON cp.id = cm.entity_id
{root_category_join('cp.id')}
WHERE l.is_final = 1
  AND j.relevance_rank <= 5

UNION ALL

SELECT
    'Запрос без привязки'                           AS loss_kind,
    l.searched_at                                   AS event_at,
    COALESCE(sh.title, st.name)                     AS shop_name,
    NULL                                            AS category_name,
    NULL                                            AS product_name,
    NULL                                            AS picker_name,
    NULL                                            AS replace_reason_name,
    l.query_normalized                              AS search_query,
    l.id                                            AS search_id,
    l.actor_key                                     AS client_key,
    NULL                                            AS order_id,
    0, 0, 0, 0, 0, 0, 0, 0,
    CAST(NULL AS DECIMAL(12, 2))                    AS margin_lost
FROM djami_store_copy.search_query_logs l
LEFT JOIN djami_store_copy.stores st ON st.id = l.store_id
LEFT JOIN shops sh ON sh.code = st.code
WHERE l.is_final = 1
  AND l.results_count = 0

UNION ALL

SELECT
    CASE
        WHEN ai.is_refused = 1 THEN 'Отказ от замены'
        WHEN ai.cheaper_loss_amount > 0 THEN 'Замена дешевле'
        WHEN ai.is_replaced = 1 THEN 'Замена'
        ELSE 'Собрано'
    END                                             AS loss_kind,
    ai.order_created_at                             AS event_at,
    ai.shop_name,
    ai.category_name,
    ai.product_name,
    ai.picker_name,
    ai.replace_reason_name,
    NULL                                            AS search_query,
    NULL                                            AS search_id,
    NULL                                            AS client_key,
    ai.order_id,
    1                                               AS is_position,
    ai.is_replaced,
    ai.is_refused,
    0                                               AS is_search_unavailable,
    0                                               AS search_amount,
    ai.cheaper_loss_amount                          AS price_delta_amount,
    ai.refused_amount,
    ai.cheaper_loss_amount + ai.refused_amount      AS loss_amount,
    CAST(NULL AS DECIMAL(12, 2))                    AS margin_lost
FROM (
    SELECT
        COALESCE(o.parent_order_id, o.id)           AS order_id,
        o.created_at                                AS order_created_at,
        s.title                                     AS shop_name,
        {root_category()}                           AS category_name,
        COALESCE(p.title, oi.origin_title, CONCAT('Товар #', COALESCE(oi.origin_product_id, oi.product_id))) AS product_name,
        {person('u', 'a.user_id', 'Сборщик')}       AS picker_name,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN COALESCE(rr.title, 'Причина не указана') END AS replace_reason_name,
        CASE WHEN oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN 1 ELSE 0 END                      AS is_replaced,
        CASE WHEN oi.status_id = 3 THEN 1 ELSE 0 END AS is_refused,
        CASE WHEN oi.status_id = 3 THEN oi.price_during_order * oi.quantity ELSE 0 END AS refused_amount,
        CASE WHEN oi.status_id <> 3 AND oi.origin_product_id IS NOT NULL AND oi.origin_product_id <> oi.product_id
             THEN GREATEST(oi.origin_price * COALESCE(oi.origin_quantity, oi.quantity)
                           - oi.price_during_order * oi.quantity, 0)
             ELSE 0 END                             AS cheaper_loss_amount
    FROM order_items oi
    JOIN assemblies a ON a.order_id = oi.order_id AND a.deleted_at IS NULL
    JOIN orders o     ON o.id = oi.order_id
    JOIN orders po    ON po.id = COALESCE(o.parent_order_id, o.id)
    LEFT JOIN shops s ON s.id = o.shop_id
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN products p ON p.id = COALESCE(oi.origin_product_id, oi.product_id)
    LEFT JOIN product_replace_reasons rr ON rr.id = oi.replace_reason_id
    {root_category_join('COALESCE(oi.origin_product_id, oi.product_id)')}
    WHERE oi.deleted_at IS NULL
      AND o.deleted_at IS NULL
      AND COALESCE(po.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
      AND {not_test_staff('u')}
) ai
""",
    columns=[
        col("event_at", dttm=True),
        col("loss_kind", "Вид потери"),
        col("shop_name", "Магазин"),
        col("category_name", "Категория"),
        col("product_name", "Товар"),
        col("picker_name", "Сборщик"),
        col("replace_reason_name", "Причина замены"),
        col("search_query", "Запрос"),
    ],
)


# R3 «Продажи и ABC». Строка = товар за выбранный период: выручка net,
# доля, накопленная доля, класс A/B/C и смена класса относительно
# предыдущего периода такой же длины.
#   * Выручка net = позиции доставленных заказов (status_id 5, родители,
#     без отменённых позиций) минус возвраты (returned_quantity), без доставки.
#   * ABC: товары по убыванию выручки; A - пока накопленная доля ДО товара
#     меньше 80% (товар, на котором доля переходит 80%, ещё в A), B - до 95%,
#     C - остальное. Пороги - в датасете, не в чарте.
#   * Период и «Магазин» забираются в SQL (remove_filter=True): классы
#     считаются по всему ассортименту периода, внешние фильтры «Категория» и
#     «Товар» потом только сужают показ.
#   * order_date = дата последнего заказа товара в периоде (временная колонка
#     для фильтра «Период»).
def _abc_sales(alias: str, period_from: str, period_to: str) -> str:
    return f"""{alias} AS (
    SELECT
        oi.product_id                                AS product_id,
        SUM(oi.price_during_order * (oi.quantity - COALESCE(oi.returned_quantity, 0))) AS revenue,
        SUM(oi.quantity - COALESCE(oi.returned_quantity, 0)) AS quantity,
        COUNT(DISTINCT o.id)                         AS orders,
        MAX(o.created_at)                            AS order_date
    FROM order_items oi
    JOIN orders o     ON o.id = oi.order_id
    LEFT JOIN shops s ON s.id = o.shop_id
    WHERE o.parent_order_id IS NULL
      AND o.status_id = 5
      AND oi.status_id <> 3
      AND oi.deleted_at IS NULL
      AND o.deleted_at IS NULL
      AND COALESCE(o.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
      AND o.created_at >= {period_from}
      AND o.created_at < {period_to}
      {shops_where('s.title')}
    GROUP BY oi.product_id
)"""


def _abc_rank(alias: str, source: str) -> str:
    return f"""{alias} AS (
    SELECT
        {source}.*,
        SUM(revenue) OVER ()                         AS total_revenue,
        SUM(revenue) OVER (ORDER BY revenue DESC, product_id
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cum_revenue
    FROM {source}
    WHERE revenue > 0
)"""


_ABC_CLASS = """CASE
            WHEN ({a}.cum_revenue - {a}.revenue) < 0.80 * {a}.total_revenue THEN 'A'
            WHEN ({a}.cum_revenue - {a}.revenue) < 0.95 * {a}.total_revenue THEN 'B'
            ELSE 'C'
        END"""

dataset(
    table_name="ops_abc_products",
    database_uuid=DB1,
    main_dttm_col="order_date",
    sql=f"""{{% set period = get_time_filter('order_date', remove_filter=True, target_type='DATETIME') %}}
{{% set shops = filter_values('shop_name', remove_filter=True) %}}
{{% set p_from = period.from_expr or "'2000-01-01 00:00:00'" %}}
{{% set p_to = period.to_expr or 'NOW() + INTERVAL 1 DAY' %}}
WITH
{_abc_sales('cur', '{{ p_from }}', '{{ p_to }}')},
{_abc_sales('prev', 'DATE_SUB({{ p_from }}, INTERVAL TIMESTAMPDIFF(SECOND, {{ p_from }}, {{ p_to }}) SECOND)', '{{ p_from }}')},
{_abc_rank('cur_r', 'cur')},
{_abc_rank('prev_r', 'prev')}
SELECT
    c.product_id,
    COALESCE(p.title, CONCAT('Товар #', c.product_id)) AS product_name,
    {root_category()}                                 AS category_name,
    {{% if shops %}}'Выбранные магазины'{{% else %}}'Все магазины'{{% endif %}} AS shop_name,
    c.revenue,
    c.quantity,
    c.orders,
    c.revenue / NULLIF(c.quantity, 0)                 AS avg_price,
    c.order_date,
    100 * c.revenue / NULLIF(c.total_revenue, 0)      AS revenue_share_pct,
    100 * c.cum_revenue / NULLIF(c.total_revenue, 0)  AS cum_share_pct,
    {_ABC_CLASS.format(a='c')}                        AS abc_class,
    {{% if period.from_expr %}}CASE
        WHEN pr.product_id IS NULL THEN 'Новый в продажах'
        WHEN {_ABC_CLASS.format(a='pr')} = {_ABC_CLASS.format(a='c')} THEN 'Без изменений'
        ELSE CONCAT({_ABC_CLASS.format(a='pr')}, ' → ', {_ABC_CLASS.format(a='c')})
    END{{% else %}}'Нет данных'{{% endif %}}                                     AS class_change
FROM cur_r c
LEFT JOIN prev_r pr ON pr.product_id = c.product_id
LEFT JOIN products p ON p.id = c.product_id
{root_category_join('c.product_id')}
""",
    columns=[
        col("order_date", dttm=True),
        col("product_name", "Товар"),
        col("category_name", "Категория"),
        col("shop_name", "Магазин"),
        col("abc_class", "Класс"),
        col("class_change", "Смена класса"),
        col("revenue", "Выручка net, ₽"),
        col("quantity", "Продано"),
        col("orders", "Заказов"),
        col("avg_price", "Средняя цена, ₽"),
        col("revenue_share_pct", "Доля, %"),
        col("cum_share_pct", "Накопленная, %"),
    ],
)


# ===========================================================================
# Вкладка «Курьеры» (ЖИВАЯ база db4: суточная задержка копий здесь не годится)
# ===========================================================================

# Строка = заказ, назначенный курьеру (последнее назначение delivery_orders,
# родительский заказ).
#   * Принял = delivery_orders.created_at, забрал = started_at, выдал = finished_at.
#     Минуты только у доставленных (status_id 5); доставки, закрытые меньше чем
#     через минуту после принятия, отмечены задним числом: в средние не входят
#     (is_backdated).
#   * В срок = выдан не позже конца слота (orders.slot_end_date).
#   * Отмена = заказ отменён после назначения курьера (отдельного «курьер
#     отказался» нет). При отмене назначение мягко удаляется, поэтому у
#     отменённых берём последнее назначение с учётом удалённых. Возврат = в заказе
#     или подзаказах есть позиция с returned_quantity > 0.
#   * Оплата: payment_type_id 1 = карта онлайн в приложении (терминала у курьера
#     нет), 2 = наличные. Суммы - orders.amount доставленных заказов минус
#     возвращённые позиции (refund_amount).
#   * Неполное подтверждение = доставленный заказ за наличные без отметки
#     об оплате (payment_status <> 'paid'). Факта сдачи наличных система не
#     хранит: «сдано» и «расхождение» = нет данных.
#   * Тариф курьера = цена доставки зоны (решение заказчика): снимок
#     orders.delivery_price_snapshot.base_price (с 23.09.2026), до него текущая
#     shop_zones.delivery_price (tariff_is_approx = 1). Бесплатная для клиента
#     доставка оплачивается курьеру по цене зоны. Заработок только по
#     доставленным. Бонусов и удержаний в системе нет.
#   * Оценка = отзыв клиента на заказ (order_reviews.rating 1-5).
COURIER_BASE_SQL = f"""SELECT
    d.id                                            AS delivery_order_id,
    o.id                                            AS order_id,
    o.shop_id                                       AS shop_id,
    s.title                                         AS shop_name,
    dl.user_id                                      AS courier_id,
    {person('u', 'dl.user_id', 'Курьер')}           AS courier_name,
    o.zone_id                                       AS zone_id,
    COALESCE(z.name, 'Зона не определена')          AS zone_name,
    d.created_at                                    AS accepted_at,
    d.started_at                                    AS picked_up_at,
    d.finished_at                                   AS delivered_at,
    DATE(d.finished_at)                             AS delivered_date,
    DATE_FORMAT(d.finished_at, '%Y-%m-%d')          AS delivered_day,
    CASE WHEN o.status_id = 5 THEN 1 ELSE 0 END     AS is_delivered,
    CASE WHEN o.status_id = 6 THEN 1 ELSE 0 END     AS is_cancelled,
    CASE WHEN COALESCE(ret.refund_amount, 0) > 0 THEN 1 ELSE 0 END AS has_return,
    CASE WHEN o.status_id = 5 AND TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) >= 60
         THEN TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) / 60 END AS delivery_minutes,
    CASE WHEN o.status_id = 5 AND TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) >= 60
              AND d.started_at >= d.created_at AND d.started_at <= d.finished_at
         THEN TIMESTAMPDIFF(SECOND, d.created_at, d.started_at) / 60 END AS accept_to_pickup_minutes,
    CASE WHEN o.status_id = 5 AND TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) >= 60
              AND d.started_at >= d.created_at AND d.started_at <= d.finished_at
         THEN TIMESTAMPDIFF(SECOND, d.started_at, d.finished_at) / 60 END AS pickup_to_deliver_minutes,
    CASE WHEN o.status_id = 5 AND TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) < 60
         THEN 1 ELSE 0 END                          AS is_backdated,
    CASE WHEN o.status_id = 5 AND o.slot_end_date IS NOT NULL AND d.finished_at <= o.slot_end_date
         THEN 1 ELSE 0 END                          AS is_on_time,
    CASE o.payment_type_id WHEN 1 THEN 'Онлайн' WHEN 2 THEN 'Наличные' ELSE 'Другое' END AS payment_method,
    CASE WHEN o.status_id = 5 AND o.payment_type_id = 2 THEN 1 ELSE 0 END AS is_cash_delivered,
    CASE WHEN o.status_id = 5 AND o.payment_type_id = 1 THEN 1 ELSE 0 END AS is_online_delivered,
    CASE WHEN o.status_id = 5 AND o.payment_type_id = 2
         THEN o.amount - COALESCE(ret.refund_amount, 0) ELSE 0 END AS cash_amount,
    CASE WHEN o.status_id = 5 AND o.payment_type_id = 1
         THEN o.amount - COALESCE(ret.refund_amount, 0) ELSE 0 END AS online_amount,
    CASE WHEN o.status_id = 5 THEN o.amount - COALESCE(ret.refund_amount, 0) ELSE 0 END AS delivered_amount,
    CASE WHEN o.status_id = 5 AND o.payment_type_id = 2 AND COALESCE(o.payment_status, '') <> 'paid'
         THEN 1 ELSE 0 END                          AS is_cash_unconfirmed,
    o.amount                                        AS order_amount,
    COALESCE(
        CAST(JSON_UNQUOTE(JSON_EXTRACT(o.delivery_price_snapshot, '$.base_price')) AS DECIMAL(10, 2)),
        z.delivery_price
    )                                               AS zone_tariff,
    CASE WHEN JSON_EXTRACT(o.delivery_price_snapshot, '$.base_price') IS NULL THEN 1 ELSE 0 END AS tariff_is_approx,
    CONCAT(COALESCE(z.name, 'Зона не определена'), ' · ', FORMAT(COALESCE(
        CAST(JSON_UNQUOTE(JSON_EXTRACT(o.delivery_price_snapshot, '$.base_price')) AS DECIMAL(10, 2)),
        z.delivery_price
    ), 0, 'ru_RU'), ' ₽')                            AS zone_tariff_label,
    CASE WHEN o.status_id = 5 THEN COALESCE(
        CAST(JSON_UNQUOTE(JSON_EXTRACT(o.delivery_price_snapshot, '$.base_price')) AS DECIMAL(10, 2)),
        z.delivery_price
    ) ELSE 0 END                                    AS courier_earning,
    rv.rating                                       AS review_rating
FROM delivery_orders d
JOIN orders o      ON o.id = d.order_id
JOIN deliveries dl ON dl.id = d.delivery_id
LEFT JOIN users u  ON u.id = dl.user_id
LEFT JOIN shops s  ON s.id = o.shop_id
LEFT JOIN shop_zones z ON z.id = o.zone_id
LEFT JOIN (
    SELECT ro.id AS order_id, SUM(ri.returned_quantity * ri.price_during_order) AS refund_amount
    FROM orders ro
    JOIN order_items ri ON ri.order_id = ro.id
    WHERE ro.parent_order_id IS NULL AND ri.returned_quantity > 0 AND ri.deleted_at IS NULL
    GROUP BY ro.id
) ret ON ret.order_id = o.id
LEFT JOIN (
    SELECT order_id, MAX(id) AS review_id FROM order_reviews
    WHERE deleted_at IS NULL GROUP BY order_id
) rl ON rl.order_id = o.id
LEFT JOIN order_reviews rv ON rv.id = rl.review_id
WHERE o.deleted_at IS NULL
  AND o.parent_order_id IS NULL
  AND COALESCE(o.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
  AND d.id = (
      SELECT MAX(d2.id) FROM delivery_orders d2
      WHERE d2.order_id = d.order_id
        AND (d2.deleted_at IS NULL OR o.status_id = 6)
  )
  AND {not_test_staff('u')}"""

_COURIER_DIMS = [
    col("accepted_at", dttm=True),
    col("shop_name", "Магазин"),
    col("courier_name", "Курьер"),
    col("zone_name", "Зона"),
    col("payment_method", "Способ оплаты"),
]

dataset(
    table_name="ops_courier_orders",
    database_uuid=DB4,
    main_dttm_col="accepted_at",
    sql=COURIER_BASE_SQL + "\n",
    columns=_COURIER_DIMS + [col("delivered_date", "День доставки", dttm=True)],
)

# R4 «Из чего складывается доставка»: два этапа строками, чтобы бар по этапам
# был обычным категориальным ECharts Bar Chart.
dataset(
    table_name="ops_courier_stages",
    database_uuid=DB4,
    main_dttm_col="accepted_at",
    sql=f"""SELECT b.accepted_at, b.shop_name, b.courier_name, b.zone_name, b.payment_method,
       'Принял → забрал' AS stage_name, 1 AS stage_order, b.accept_to_pickup_minutes AS stage_minutes
FROM (
{COURIER_BASE_SQL}
) b
WHERE b.accept_to_pickup_minutes IS NOT NULL
UNION ALL
SELECT b.accepted_at, b.shop_name, b.courier_name, b.zone_name, b.payment_method,
       'Забрал → выдал', 2, b.pickup_to_deliver_minutes
FROM (
{COURIER_BASE_SQL}
) b
WHERE b.pickup_to_deliver_minutes IS NOT NULL
""",
    columns=_COURIER_DIMS + [col("stage_name", "Этап")],
)


def _courier_period_base() -> str:
    """Заказы курьеров за период, с фильтрами «Магазин» и «Зона» внутри SQL."""
    return f"""SELECT * FROM (
{COURIER_BASE_SQL}
) b
WHERE 1 = 1
  {period_where('b.accepted_at')}
  {shops_where('b.shop_name')}
  {{% if zones %}}AND b.zone_name IN {{{{ zones | where_in }}}}{{% endif %}}"""


_COURIER_JINJA_HEAD = """{% set period = get_time_filter('accepted_at', remove_filter=True, target_type='DATETIME') %}
{% set shops = filter_values('shop_name', remove_filter=True) %}
{% set zones = filter_values('zone_name', remove_filter=True) %}
"""

# R4 таблица «Детализация показателей»: строка = курьер за период. Медиана и
# p90 считаются по заказам (а не по средним), поэтому период внутри SQL.
# Заказов в час и за смену - по сменам курьера (shifts: начало/конец смены);
# смены курьеры открывают редко, без смен в периоде значение пустое.
dataset(
    table_name="ops_courier_stats",
    database_uuid=DB4,
    main_dttm_col="accepted_at",
    sql=_COURIER_JINJA_HEAD + f"""WITH base AS (
{_courier_period_base()}
),
ranked AS (
    SELECT courier_id, delivery_minutes,
           ROW_NUMBER() OVER (PARTITION BY courier_id ORDER BY delivery_minutes) AS rn,
           COUNT(*) OVER (PARTITION BY courier_id)                          AS cnt
    FROM base
    WHERE delivery_minutes IS NOT NULL
),
pct AS (
    SELECT courier_id,
           AVG(CASE WHEN rn IN (FLOOR((cnt + 1) / 2), CEIL((cnt + 1) / 2)) THEN delivery_minutes END) AS median_minutes,
           MIN(CASE WHEN rn >= CEIL(0.9 * cnt) THEN delivery_minutes END)                          AS p90_minutes
    FROM ranked
    GROUP BY courier_id
),
sh AS (
    SELECT user_id AS courier_id,
           COUNT(*) AS shifts,
           SUM(TIMESTAMPDIFF(SECOND, created_at, finished_at)) / 3600 AS shift_hours
    FROM shifts
    WHERE deleted_at IS NULL AND finished_at IS NOT NULL
      {period_where('created_at')}
    GROUP BY user_id
)
SELECT
    MAX(b.accepted_at)                                AS accepted_at,
    MAX(b.shop_name)                                  AS shop_name,
    b.courier_name,
    {{% if zones %}}'Выбранные зоны'{{% else %}}'Все зоны'{{% endif %}} AS zone_name,
    SUM(b.is_delivered)                               AS delivered,
    SUM(b.is_cancelled)                               AS cancelled,
    SUM(b.has_return)                                 AS returned,
    100 * SUM(b.is_cancelled) / NULLIF(COUNT(*), 0)   AS cancel_pct,
    AVG(b.delivery_minutes)                           AS avg_minutes,
    MAX(pct.median_minutes)                           AS median_minutes,
    MAX(pct.p90_minutes)                              AS p90_minutes,
    AVG(b.accept_to_pickup_minutes)                   AS accept_to_pickup_minutes,
    AVG(b.pickup_to_deliver_minutes)                  AS pickup_to_deliver_minutes,
    100 * SUM(b.is_on_time) / NULLIF(SUM(b.is_delivered), 0) AS on_time_pct,
    SUM(b.is_delivered) / NULLIF(MAX(sh.shift_hours), 0) AS orders_per_hour,
    SUM(b.is_delivered) / NULLIF(MAX(sh.shifts), 0)   AS orders_per_shift,
    AVG(b.review_rating)                              AS avg_rating
FROM base b
LEFT JOIN pct ON pct.courier_id = b.courier_id
LEFT JOIN sh  ON sh.courier_id = b.courier_id
GROUP BY b.courier_id, b.courier_name
""",
    columns=[
        col("accepted_at", dttm=True),
        col("shop_name", "Магазин"),
        col("courier_name", "Курьер"),
        col("zone_name", "Зона"),
        col("delivered", "Доставлено"),
        col("cancelled", "Отмены"),
        col("returned", "Возвраты"),
        col("cancel_pct", "% отмен"),
        col("avg_minutes", "Среднее, мин"),
        col("median_minutes", "Медиана, мин"),
        col("p90_minutes", "p90, мин"),
        col("accept_to_pickup_minutes", "Принял → забрал, мин"),
        col("pickup_to_deliver_minutes", "Забрал → выдал, мин"),
        col("on_time_pct", "В срок, %"),
        col("orders_per_hour", "Заказов/ч"),
        col("orders_per_shift", "Заказов/смена"),
        col("avg_rating", "Оценка"),
    ],
)

# R7 таблица «Начисления по курьерам и зонам»: курьер × зона × тариф за период.
dataset(
    table_name="ops_courier_earnings",
    database_uuid=DB4,
    main_dttm_col="accepted_at",
    sql=_COURIER_JINJA_HEAD + f"""SELECT
    MAX(b.accepted_at)                                AS accepted_at,
    b.shop_name,
    b.courier_name,
    b.zone_name,
    SUM(b.is_delivered)                               AS delivered,
    b.zone_tariff,
    MAX(b.tariff_is_approx)                           AS tariff_is_approx,
    SUM(b.courier_earning)                            AS base_amount,
    CAST(NULL AS DECIMAL(12, 2))                      AS bonus_amount,
    CAST(NULL AS DECIMAL(12, 2))                      AS deduction_amount,
    SUM(b.courier_earning)                            AS total_amount
FROM (
{_courier_period_base()}
) b
WHERE b.is_delivered = 1
GROUP BY b.shop_name, b.courier_name, b.zone_name, b.zone_tariff
""",
    columns=[
        col("accepted_at", dttm=True),
        col("shop_name", "Магазин"),
        col("courier_name", "Курьер"),
        col("zone_name", "Зона"),
        col("delivered", "Заказов"),
        col("zone_tariff", "Тариф, ₽"),
        col("base_amount", "База, ₽"),
        col("bonus_amount", "Бонусы, ₽"),
        col("deduction_amount", "Удержания, ₽"),
        col("total_amount", "Итого, ₽"),
    ],
)


# ===========================================================================
# Вкладка «Сборщики» (копия db1)
# ===========================================================================

# Строка = сборщик × заказ клиента (родитель): все сборки (подзаказы сплита)
# этого сборщика по заказу.
#   * Чистое время = первая сборка сборщика (assemblies.created_at) -> последняя
#     (finished_at), «до передачи курьеру». Отдельного момента «назначен
#     сборщику» система не хранит (cooking_started_at пуст), поэтому полное
#     время = нет данных. Сборки дольше суток в среднее не входят (is_over_day).
#   * Собрано единиц = собранные позиции (status_id <> 3), отклонённые не входят.
#   * Чек заказа = выручка родителя (price_during_order * quantity, без
#     отменённых позиций), только доставленные; order_weight = 1 / число
#     сборщиков заказа, чтобы средний чек считался по уникальным заказам.
#   * Жалоба = отзыв на заказ с типом ошибки, проставленным оператором
#     (order_reviews.error_type), относится на сборщиков заказа. Жалоб на
#     курьеров и компенсаций система не хранит.
#   * Несогласованная замена = замена в заказе без опции «Позвонить, если
#     товара нет».
PICKER_BASE_SQL = f"""SELECT
    CONCAT(pa.picker_id, '-', pa.order_id)          AS picker_order_key,
    pa.order_id                                     AS order_id,
    s.title                                         AS shop_name,
    pa.picker_id                                    AS picker_id,
    {person('u', 'pa.picker_id', 'Сборщик')}        AS picker_name,
    pa.started_at                                   AS assembly_started_at,
    pa.finished_at                                  AS assembly_finished_at,
    CASE WHEN pa.unfinished = 0 THEN 1 ELSE 0 END   AS is_finished,
    CASE WHEN pa.unfinished = 0 AND pa.finished_at >= pa.started_at
              AND TIMESTAMPDIFF(MINUTE, pa.started_at, pa.finished_at) <= 1440
         THEN TIMESTAMPDIFF(SECOND, pa.started_at, pa.finished_at) / 60 END AS assembly_minutes,
    CASE WHEN pa.unfinished = 0 AND TIMESTAMPDIFF(MINUTE, pa.started_at, pa.finished_at) > 1440
         THEN 1 ELSE 0 END                          AS is_over_day,
    pa.positions_ordered,
    pa.positions_assembled,
    pa.positions_replaced,
    pa.positions_not_found,
    CASE WHEN JSON_SEARCH(o.checkout_options, 'one', 'call_if_no_exists') IS NULL
         THEN pa.positions_replaced ELSE 0 END      AS positions_no_offer,
    CASE WHEN pa.positions_replaced > 0 THEN 1 ELSE 0 END AS has_replacement,
    CASE WHEN o.status_id = 5 THEN rev.revenue END  AS order_check,
    1.0 / COUNT(*) OVER (PARTITION BY pa.order_id)  AS order_weight,
    rv.rating                                       AS review_rating,
    CASE rv.error_type
        WHEN 'shortage' THEN 'Недовоз'
        WHEN 'wrong_item' THEN 'Пересорт'
        WHEN 'expired' THEN 'Просрочка'
        WHEN 'other' THEN 'Другое'
    END                                             AS error_type_name,
    CASE WHEN rv.error_type IS NOT NULL THEN 1 ELSE 0 END AS has_error,
    CASE WHEN rv.rating <= 3 AND rv.error_type IS NULL THEN 1 ELSE 0 END AS is_low_rating_unmarked
FROM (
    SELECT
        COALESCE(ao.parent_order_id, ao.id)          AS order_id,
        a.user_id                                    AS picker_id,
        MIN(a.created_at)                            AS started_at,
        MAX(a.finished_at)                           AS finished_at,
        SUM(CASE WHEN a.finished_at IS NULL THEN 1 ELSE 0 END) AS unfinished,
        SUM(COALESCE(it.positions_ordered, 0))       AS positions_ordered,
        SUM(COALESCE(it.positions_assembled, 0))     AS positions_assembled,
        SUM(COALESCE(it.positions_replaced, 0))      AS positions_replaced,
        SUM(COALESCE(it.positions_not_found, 0))     AS positions_not_found
    FROM assemblies a
    JOIN orders ao ON ao.id = a.order_id AND ao.deleted_at IS NULL
    LEFT JOIN (
        SELECT
            oi.order_id,
            COUNT(*)                                                          AS positions_ordered,
            SUM(CASE WHEN oi.status_id <> 3 THEN 1 ELSE 0 END)                AS positions_assembled,
            SUM(CASE WHEN oi.origin_product_id IS NOT NULL
                      AND oi.origin_product_id <> oi.product_id THEN 1 ELSE 0 END) AS positions_replaced,
            SUM(CASE WHEN oi.status_id = 3
                      AND COALESCE(oi.cancel_reason_id, 0) <> 5 THEN 1 ELSE 0 END) AS positions_not_found
        FROM order_items oi
        WHERE oi.deleted_at IS NULL
        GROUP BY oi.order_id
    ) it ON it.order_id = a.order_id
    WHERE a.deleted_at IS NULL
    GROUP BY COALESCE(ao.parent_order_id, ao.id), a.user_id
) pa
JOIN orders o     ON o.id = pa.order_id
LEFT JOIN shops s ON s.id = o.shop_id
LEFT JOIN users u ON u.id = pa.picker_id
LEFT JOIN (
    SELECT oi.order_id, SUM(oi.price_during_order * oi.quantity) AS revenue
    FROM order_items oi
    WHERE oi.deleted_at IS NULL AND oi.status_id <> 3
    GROUP BY oi.order_id
) rev ON rev.order_id = pa.order_id
LEFT JOIN (
    SELECT order_id, MAX(id) AS review_id
    FROM order_reviews
    WHERE deleted_at IS NULL
    GROUP BY order_id
) rl ON rl.order_id = pa.order_id
LEFT JOIN order_reviews rv ON rv.id = rl.review_id
WHERE COALESCE(o.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
  AND {not_test_staff('u')}"""

dataset(
    table_name="ops_picker_orders",
    database_uuid=DB1,
    main_dttm_col="assembly_started_at",
    sql=PICKER_BASE_SQL + "\n",
    columns=[
        col("assembly_started_at", dttm=True),
        col("shop_name", "Магазин"),
        col("picker_name", "Сборщик"),
        col("error_type_name", "Тип ошибки"),
    ],
)

# R8 таблица «Детализация показателей»: строка = сборщик за период (медиана и
# p90 по заказам, заказов в час - по сменам shifts).
dataset(
    table_name="ops_picker_stats",
    database_uuid=DB1,
    main_dttm_col="assembly_started_at",
    sql=f"""{{% set period = get_time_filter('assembly_started_at', remove_filter=True, target_type='DATETIME') %}}
{{% set shops = filter_values('shop_name', remove_filter=True) %}}
WITH base AS (
    SELECT * FROM (
{PICKER_BASE_SQL}
    ) b
    WHERE 1 = 1
      {period_where('b.assembly_started_at')}
      {shops_where('b.shop_name')}
),
ranked AS (
    SELECT picker_id, assembly_minutes,
           ROW_NUMBER() OVER (PARTITION BY picker_id ORDER BY assembly_minutes) AS rn,
           COUNT(*) OVER (PARTITION BY picker_id)                          AS cnt
    FROM base
    WHERE assembly_minutes IS NOT NULL
),
pct AS (
    SELECT picker_id,
           AVG(CASE WHEN rn IN (FLOOR((cnt + 1) / 2), CEIL((cnt + 1) / 2)) THEN assembly_minutes END) AS median_minutes,
           MIN(CASE WHEN rn >= CEIL(0.9 * cnt) THEN assembly_minutes END)                          AS p90_minutes
    FROM ranked
    GROUP BY picker_id
),
sh AS (
    SELECT user_id AS picker_id,
           SUM(TIMESTAMPDIFF(SECOND, created_at, finished_at)) / 3600 AS shift_hours
    FROM shifts
    WHERE deleted_at IS NULL AND finished_at IS NOT NULL
      {period_where('created_at')}
    GROUP BY user_id
)
SELECT
    MAX(b.assembly_started_at)                        AS assembly_started_at,
    {{% if shops %}}'Выбранные магазины'{{% else %}}'Все магазины'{{% endif %}} AS shop_name,
    b.picker_name,
    COUNT(DISTINCT CASE WHEN b.is_finished = 1 THEN b.order_id END) AS orders,
    SUM(CASE WHEN b.is_finished = 1 THEN b.positions_assembled ELSE 0 END) AS units,
    AVG(b.assembly_minutes)                           AS net_minutes,
    CAST(NULL AS DECIMAL(10, 1))                      AS full_minutes,
    MAX(pct.median_minutes)                           AS median_minutes,
    MAX(pct.p90_minutes)                              AS p90_minutes,
    COUNT(DISTINCT CASE WHEN b.is_finished = 1 THEN b.order_id END) / NULLIF(MAX(sh.shift_hours), 0) AS orders_per_hour,
    SUM(CASE WHEN b.is_finished = 1 THEN b.positions_assembled ELSE 0 END)
        / NULLIF(COUNT(DISTINCT CASE WHEN b.is_finished = 1 THEN b.order_id END), 0) AS units_per_order,
    SUM(b.order_check * b.order_weight) / NULLIF(SUM(CASE WHEN b.order_check IS NOT NULL THEN b.order_weight END), 0) AS avg_check,
    100 * SUM(b.has_replacement) / NULLIF(COUNT(*), 0) AS replaced_pct
FROM base b
LEFT JOIN pct ON pct.picker_id = b.picker_id
LEFT JOIN sh  ON sh.picker_id = b.picker_id
GROUP BY b.picker_id, b.picker_name
""",
    columns=[
        col("assembly_started_at", dttm=True),
        col("shop_name", "Магазин"),
        col("picker_name", "Сборщик"),
        col("orders", "Заказов"),
        col("units", "Единиц"),
        col("net_minutes", "Чистое время, мин"),
        col("full_minutes", "Полное время, мин"),
        col("median_minutes", "Медиана, мин"),
        col("p90_minutes", "p90, мин"),
        col("orders_per_hour", "Заказов/ч"),
        col("units_per_order", "Единиц/заказ"),
        col("avg_check", "Средний чек, ₽"),
        col("replaced_pct", "% с заменой"),
    ],
)


# ===========================================================================
# Вкладка «Клиенты» (витрина, копия db2)
# ===========================================================================
# Клиент = пользователь витрины (orders.user_id, стабилен между заказами).
# Покупка = заказ витрины со статусом delivered; бизнес-дата = created_at.
# Контактов в датасетах нет: только ID клиента.
#
# RFM (ТЗ R12): R по давности 0-7 / 8-14 / 15-30 / 31-60 / 61+ дней -> R5..R1;
# F по числу заказов за 365 дней 1 / 2 / 3-4 / 5-9 / 10+ -> F1..F5;
# M по сумме за 365 дней <1 тыс. / 1-3 / 3-7 / 7-15 / 15+ тыс. ₽ -> M1..M5.
# Сегменты взаимоисключающие, проверяются по порядку:
#   Потерянные - 90+ дней без покупки; Риск - 31-89 дней; Новые - одна покупка
#   за 365 дней; Лучшие - R>=4, F>=4, M>=4; Лояльные - F>=3; Развивающиеся - остальные.
# RFM на произвольную дату считается из истории заказов (история с 19.05.2026,
# ежедневных снимков нет): дата среза = конец выбранного периода, первый срез
# (R14) = начало периода.

SEGMENTS = ["Лучшие", "Лояльные", "Развивающиеся", "Новые", "Риск", "Потерянные"]

SEGMENT_REASON = {
    "Потерянные": "Нет покупок 90 дней и больше",
    "Риск": "31–89 дней без покупки",
    "Новые": "Одна покупка за 365 дней",
    "Лучшие": "R≥4 · F≥4 · M≥4",
    "Лояльные": "3 и больше заказов за 365 дней",
    "Развивающиеся": "2 заказа за 365 дней, покупает недавно",
}
SEGMENT_ACTION = {
    "Потерянные": "Реактивация: персональное предложение",
    "Риск": "Напомнить: промокод на следующий заказ",
    "Новые": "Довести до второго заказа",
    "Лучшие": "Удерживать: бонусы и ранний доступ",
    "Лояльные": "Растить чек: наборы и рекомендации",
    "Развивающиеся": "Повышать частоту: напоминания",
}


def _case_map(expr: str, mapping: dict) -> str:
    whens = " ".join(f"WHEN '{k}' THEN '{v}'" for k, v in mapping.items())
    return f"CASE {expr} {whens} END"


def client_orders_sql(schema: str = "") -> str:
    """Доставленные заказы витрины без тестовых клиентов."""
    return f"""SELECT
        o.id                                         AS order_id,
        o.user_id                                    AS client_id,
        o.created_at                                 AS ordered_at,
        o.total                                      AS total,
        st.name                                      AS shop_name
    FROM {schema}orders o
    LEFT JOIN {schema}stores st ON st.id = o.store_id
    WHERE o.deleted_at IS NULL
      AND o.status = 'delivered'
      AND o.user_id IS NOT NULL
      AND o.user_id NOT IN {NOT_TEST_USERS_STORE}"""


def rfm_snapshot_sql(asof: str, schema: str = "") -> str:
    """Состояние клиента на дату среза asof (SQL-выражение, не включительно)."""
    return f"""SELECT
        x.*,
        CASE
            WHEN x.recency_days >= 90 THEN 'Потерянные'
            WHEN x.recency_days >= 31 THEN 'Риск'
            WHEN x.frequency_365 <= 1 THEN 'Новые'
            WHEN x.r_score >= 4 AND x.f_score >= 4 AND x.m_score >= 4 THEN 'Лучшие'
            WHEN x.f_score >= 3 THEN 'Лояльные'
            ELSE 'Развивающиеся'
        END                                          AS segment
    FROM (
        SELECT
            co.client_id,
            MIN(co.ordered_at)                       AS first_order_at,
            MAX(co.ordered_at)                       AS last_order_at,
            DATEDIFF({asof}, MAX(co.ordered_at))     AS recency_days,
            SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN 1 ELSE 0 END) AS frequency_365,
            SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN co.total ELSE 0 END) AS monetary_365,
            SUBSTRING_INDEX(GROUP_CONCAT(co.shop_name ORDER BY co.ordered_at DESC SEPARATOR '|'), '|', 1) AS last_shop_name,
            CASE
                WHEN DATEDIFF({asof}, MAX(co.ordered_at)) <= 7 THEN 5
                WHEN DATEDIFF({asof}, MAX(co.ordered_at)) <= 14 THEN 4
                WHEN DATEDIFF({asof}, MAX(co.ordered_at)) <= 30 THEN 3
                WHEN DATEDIFF({asof}, MAX(co.ordered_at)) <= 60 THEN 2
                ELSE 1
            END                                      AS r_score,
            CASE
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN 1 ELSE 0 END) >= 10 THEN 5
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN 1 ELSE 0 END) >= 5 THEN 4
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN 1 ELSE 0 END) >= 3 THEN 3
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN 1 ELSE 0 END) = 2 THEN 2
                ELSE 1
            END                                      AS f_score,
            CASE
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN co.total ELSE 0 END) >= 15000 THEN 5
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN co.total ELSE 0 END) >= 7000 THEN 4
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN co.total ELSE 0 END) >= 3000 THEN 3
                WHEN SUM(CASE WHEN co.ordered_at >= DATE_SUB({asof}, INTERVAL 365 DAY) THEN co.total ELSE 0 END) >= 1000 THEN 2
                ELSE 1
            END                                      AS m_score
        FROM ({client_orders_sql(schema)}) co
        WHERE co.ordered_at < {asof}
        GROUP BY co.client_id
    ) x"""


# Ведущие пробелы выравнивают число до трёх знаков: сортировка по подписи
# (ORDER BY в чарте) совпадает с порядком корзин, пробелы в подписи не видны.
_RECENCY_BUCKET = """CASE
        WHEN b.recency_days <= 7 THEN '  0–7 дней'
        WHEN b.recency_days <= 14 THEN '  8–14 дней'
        WHEN b.recency_days <= 30 THEN ' 15–30 дней'
        WHEN b.recency_days <= 60 THEN ' 31–60 дней'
        WHEN b.recency_days <= 89 THEN ' 61–89 дней'
        WHEN b.recency_days <= 179 THEN ' 90–179 дней'
        ELSE '180+ дней'
    END"""

_RECENCY_ORDER = """CASE
        WHEN b.recency_days <= 7 THEN 1 WHEN b.recency_days <= 14 THEN 2
        WHEN b.recency_days <= 30 THEN 3 WHEN b.recency_days <= 60 THEN 4
        WHEN b.recency_days <= 89 THEN 5 WHEN b.recency_days <= 179 THEN 6 ELSE 7
    END"""

_CLIENT_JINJA_HEAD = """{% set period = get_time_filter('last_order_at', remove_filter=True, target_type='DATETIME') %}
"""


def client_rfm_sql(schema: str = "") -> str:
    """Клиент на двух срезах: a_* - начало периода, без префикса - конец периода."""
    asof = ASOF_EXPR
    since = FROM_EXPR
    return f"""SELECT
    b.client_id,
    b.first_order_at,
    b.last_order_at,
    DATE_FORMAT(b.first_order_at, '%Y-%m')           AS cohort_month,
    b.recency_days,
    b.frequency_365,
    b.monetary_365,
    b.monetary_365 / NULLIF(b.frequency_365, 0)      AS avg_check_365,
    CONCAT('R', b.r_score)                           AS r_score,
    CONCAT('F', b.f_score)                           AS f_score,
    CONCAT('M', b.m_score)                           AS m_score,
    CONCAT(b.r_score, '/', b.f_score, '/', b.m_score) AS rfm_scores,
    CONCAT(b.r_score, b.f_score, b.m_score)          AS rfm_code,
    b.segment,
    {_case_map('b.segment', SEGMENT_REASON)}         AS segment_reason,
    {_case_map('b.segment', SEGMENT_ACTION)}         AS segment_action,
    b.last_shop_name                                 AS shop_name,
    {_RECENCY_BUCKET}                                AS recency_bucket,
    {_RECENCY_ORDER}                                 AS recency_order,
    CASE WHEN b.recency_days BETWEEN 31 AND 89 THEN 1 ELSE 0 END AS is_risk,
    CASE WHEN b.recency_days >= 90 THEN 1 ELSE 0 END AS is_lost,
    COALESCE(a.segment, 'Не было в базе')            AS a_segment,
    CONCAT(a.r_score, '/', a.f_score, '/', a.m_score) AS a_rfm_scores,
    a.monetary_365                                   AS a_monetary_365,
    CONCAT(COALESCE(a.segment, 'Не было в базе'), ' → ', b.segment) AS transition_pair,
    CASE
        WHEN a.client_id IS NULL THEN 'Впервые купили'
        WHEN a.segment = b.segment THEN 'Сохранили сегмент'
        WHEN b.segment = 'Риск' THEN 'Перешли в риск'
        WHEN b.segment = 'Потерянные' THEN 'Стали потерянными'
        WHEN a.segment IN ('Риск', 'Потерянные') THEN 'Вернулись в активные'
        ELSE 'Сменили сегмент'
    END                                              AS transition_kind
FROM ({rfm_snapshot_sql(asof, schema)}) b
LEFT JOIN ({rfm_snapshot_sql(since, schema)}) a ON a.client_id = b.client_id"""


# R10 KPI: строка = покупка. is_first - первая покупка клиента вообще,
# is_returned - покупка после перерыва 90+ дней, is_repeat_client - клиент
# покупал до начала выбранного периода.
dataset(
    table_name="ops_client_orders",
    database_uuid=DB2,
    main_dttm_col="ordered_at",
    sql=f"""{{% set period = get_time_filter('ordered_at', target_type='DATETIME') %}}
SELECT
    t.*,
    {{% if period.from_expr %}}CASE WHEN t.first_order_at < {{{{ period.from_expr }}}} THEN 1 ELSE 0 END{{% else %}}0{{% endif %}} AS is_repeat_client
FROM (
    SELECT
        co.*,
        MIN(co.ordered_at) OVER (PARTITION BY co.client_id)                     AS first_order_at,
        LAG(co.ordered_at) OVER (PARTITION BY co.client_id ORDER BY co.ordered_at, co.order_id) AS prev_order_at,
        CASE WHEN co.ordered_at = MIN(co.ordered_at) OVER (PARTITION BY co.client_id) THEN 1 ELSE 0 END AS is_first,
        CASE WHEN DATEDIFF(co.ordered_at,
                           LAG(co.ordered_at) OVER (PARTITION BY co.client_id ORDER BY co.ordered_at, co.order_id)) >= 90
             THEN 1 ELSE 0 END                                                  AS is_returned
    FROM ({client_orders_sql()}) co
) t
""",
    columns=[
        col("ordered_at", dttm=True),
        col("shop_name", "Магазин"),
        col("client_id", "ID клиента"),
    ],
)


# R10 линия, бары и таблица «Динамика по периодам»: строка = магазин ×
# группировка (Месяц / Неделя) × интервал. Покупатели и прочее считаются по
# интервалу целиком, поэтому агрегировано в SQL:
#   * new_clients - первая покупка в интервале; repeat_clients - покупали до
#     начала интервала; returned_clients - покупка после перерыва 90+ дней;
#   * lost_clients - у клиента в интервале исполнилось 90 дней без покупки;
#   * active_end - покупали за 30 дней до конца интервала (или до сегодня).
# Неделя начинается с понедельника. Фильтр «Группировка» обязателен.
# Период дашборда отбирает интервалы по пересечению (в SQL, remove_filter):
# при периоде с 21.09 сентябрь тоже виден, а не выпадает целиком.
dataset(
    table_name="ops_client_periods",
    database_uuid=DB2,
    main_dttm_col="period_start",
    sql=f"""{{% set period = get_time_filter('period_start', remove_filter=True, target_type='DATETIME') %}}
WITH RECURSIVE
co AS (
    SELECT
        c.*,
        LAG(c.ordered_at) OVER (PARTITION BY c.client_id ORDER BY c.ordered_at, c.order_id)  AS prev_order_at,
        LEAD(c.ordered_at) OVER (PARTITION BY c.client_id ORDER BY c.ordered_at, c.order_id) AS next_order_at,
        MIN(c.ordered_at) OVER (PARTITION BY c.client_id)                                    AS first_order_at
    FROM ({client_orders_sql()}) c
),
months AS (
    SELECT CAST(DATE_FORMAT((SELECT MIN(ordered_at) FROM co), '%Y-%m-01') AS DATETIME) AS ps
    UNION ALL
    SELECT ps + INTERVAL 1 MONTH FROM months WHERE ps + INTERVAL 1 MONTH <= NOW()
),
weeks AS (
    SELECT CAST(DATE_SUB(DATE((SELECT MIN(ordered_at) FROM co)),
                         INTERVAL WEEKDAY((SELECT MIN(ordered_at) FROM co)) DAY) AS DATETIME) AS ps
    UNION ALL
    SELECT ps + INTERVAL 1 WEEK FROM weeks WHERE ps + INTERVAL 1 WEEK <= NOW()
),
periods AS (
    SELECT 'Месяц' AS grain, ps, ps + INTERVAL 1 MONTH AS pe FROM months
    UNION ALL
    SELECT 'Неделя', ps, ps + INTERVAL 1 WEEK FROM weeks
),
shops AS (
    SELECT DISTINCT shop_name FROM co
)
SELECT
    p.grain,
    p.ps                                              AS period_start,
    CASE WHEN p.grain = 'Месяц' THEN DATE_FORMAT(p.ps, '%Y-%m')
         ELSE DATE_FORMAT(p.ps, '%Y-%m-%d') END        AS period_label,
    s.shop_name,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe) AS buyers,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe
        AND x.first_order_at >= p.ps)                 AS new_clients,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe
        AND x.first_order_at < p.ps)                  AS repeat_clients,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe
        AND DATEDIFF(x.ordered_at, x.prev_order_at) >= 90) AS returned_clients,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name
        AND (x.next_order_at IS NULL OR x.next_order_at >= x.ordered_at + INTERVAL 90 DAY)
        AND x.ordered_at + INTERVAL 90 DAY >= p.ps
        AND x.ordered_at + INTERVAL 90 DAY < LEAST(p.pe, NOW())) AS lost_clients,
    (SELECT COUNT(DISTINCT x.client_id) FROM co x
      WHERE x.shop_name = s.shop_name
        AND x.ordered_at < LEAST(p.pe, NOW())
        AND x.ordered_at >= LEAST(p.pe, NOW()) - INTERVAL 30 DAY) AS active_end,
    (SELECT COUNT(*) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe) AS orders,
    (SELECT SUM(x.total) FROM co x
      WHERE x.shop_name = s.shop_name AND x.ordered_at >= p.ps AND x.ordered_at < p.pe) AS revenue,
    CASE WHEN p.pe > NOW() THEN 1 ELSE 0 END          AS is_incomplete
FROM periods p
CROSS JOIN shops s
WHERE 1 = 1
  {{% if period.from_expr %}}AND p.pe > {{{{ period.from_expr }}}}{{% endif %}}
  {{% if period.to_expr %}}AND p.ps < {{{{ period.to_expr }}}}{{% endif %}}
""",
    columns=[
        col("period_start", "Период", dttm=True),
        col("grain", "Группировка"),
        col("period_label", "Период"),
        col("shop_name", "Магазин"),
        col("buyers", "Покупатели"),
        col("new_clients", "Новые"),
        col("repeat_clients", "Повторные"),
        col("returned_clients", "Вернувшиеся"),
        col("lost_clients", "Перешли в потерянные"),
        col("active_end", "Активны на конец"),
        col("orders", "Заказы"),
        col("revenue", "Выручка net, ₽"),
    ],
)


# R11-R14: строка = клиент с покупкой до даты среза (конец периода), RFM на
# двух срезах. Период забирается в SQL (remove_filter=True); у чартов adhoc
# TEMPORAL_RANGE по last_order_at обязателен.
_CLIENT_RFM_COLUMNS = [
    col("last_order_at", "Последняя покупка", dttm=True),
    col("first_order_at", "Первая покупка", dttm=True),
    col("client_id", "ID клиента"),
    col("shop_name", "Магазин"),
    col("segment", "Сегмент"),
    col("a_segment", "Сегмент на первом срезе"),
    col("cohort_month", "Когорта"),
    col("r_score", "R"),
    col("f_score", "F"),
    col("m_score", "M"),
    col("rfm_scores", "R/F/M"),
    col("rfm_code", "RFM"),
    col("recency_days", "Дней без заказа"),
    col("frequency_365", "Заказов за 365 дней"),
    col("monetary_365", "Выручка за 365 дней, ₽"),
    col("avg_check_365", "Средний чек, ₽"),
    col("segment_reason", "Причина сегмента"),
    col("segment_action", "Действие"),
    col("recency_bucket", "Давность"),
    col("transition_pair", "Переход"),
    col("transition_kind", "Тип перехода"),
    col("a_rfm_scores", "R/F/M на первом срезе"),
    col("a_monetary_365", "Выручка на первом срезе, ₽"),
]

dataset(
    table_name="ops_client_rfm",
    database_uuid=DB2,
    main_dttm_col="last_order_at",
    sql=_CLIENT_JINJA_HEAD + client_rfm_sql() + "\n",
    columns=_CLIENT_RFM_COLUMNS,
)

# R14 «Изменение численности сегментов»: тот же клиент двумя строками (срез 1
# и срез 2), slice_segment - сегмент на этом срезе. segment (конец периода) и
# a_segment оставлены для фильтров «Сегмент» и «Сегмент на первом срезе».
dataset(
    table_name="ops_client_segment_slices",
    database_uuid=DB2,
    main_dttm_col="last_order_at",
    sql=_CLIENT_JINJA_HEAD + f"""SELECT r.*, 'Срез 1 · начало периода' AS slice_name, r.a_segment AS slice_segment
FROM ({client_rfm_sql()}) r
WHERE r.a_segment <> 'Не было в базе'
UNION ALL
SELECT r.*, 'Срез 2 · конец периода', r.segment
FROM ({client_rfm_sql()}) r
""",
    columns=_CLIENT_RFM_COLUMNS + [
        col("slice_name", "Срез"),
        col("slice_segment", "Сегмент на срезе"),
    ],
)


# R11 «Когортное удержание»: строка = когорта (месяц первой покупки) × месяц
# жизни M0..M11. Удержание = клиенты когорты с покупкой в месяце M / размер
# когорты. Незавершённые месяцы (текущий и будущие) не выводятся, ячейка пустая.
# Временной колонки у датасета нет: фильтр «Период» (по умолчанию 30 дней)
# отрезал бы все когорты старше месяца, матрица всегда за всю историю.
dataset(
    table_name="ops_client_cohorts",
    database_uuid=DB2,
    main_dttm_col=None,
    sql=f"""WITH co AS (
    SELECT c.*, MIN(c.ordered_at) OVER (PARTITION BY c.client_id) AS first_order_at
    FROM ({client_orders_sql()}) c
),
cohort AS (
    SELECT client_id,
           CAST(DATE_FORMAT(MIN(first_order_at), '%Y-%m-01') AS DATETIME) AS cohort_start,
           SUBSTRING_INDEX(GROUP_CONCAT(shop_name ORDER BY ordered_at SEPARATOR '|'), '|', 1) AS shop_name
    FROM co
    GROUP BY client_id
),
activity AS (
    SELECT DISTINCT co.client_id,
           TIMESTAMPDIFF(MONTH, ch.cohort_start, CAST(DATE_FORMAT(co.ordered_at, '%Y-%m-01') AS DATETIME)) AS life_month
    FROM co
    JOIN cohort ch ON ch.client_id = co.client_id
),
sizes AS (
    SELECT cohort_start, shop_name, COUNT(*) AS cohort_size FROM cohort GROUP BY cohort_start, shop_name
),
grid AS (
    SELECT s.cohort_start, s.shop_name, s.cohort_size, m.n AS life_month
    FROM sizes s
    JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
          UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
          UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) m
    WHERE s.cohort_start + INTERVAL (m.n + 1) MONTH <= CAST(DATE_FORMAT(NOW(), '%Y-%m-01') AS DATETIME)
)
SELECT
    g.cohort_start,
    DATE_FORMAT(g.cohort_start, '%Y-%m')              AS cohort_month,
    CONCAT('M', g.life_month)                         AS life_month,
    g.shop_name,
    g.cohort_size,
    (SELECT COUNT(*) FROM activity a JOIN cohort ch ON ch.client_id = a.client_id
      WHERE ch.cohort_start = g.cohort_start AND ch.shop_name = g.shop_name
        AND a.life_month = g.life_month)              AS active_clients
FROM grid g
""",
    columns=[
        col("cohort_month", "Когорта"),
        col("life_month", "Месяц жизни"),
        col("shop_name", "Магазин"),
    ],
)


# R15 «Отзывы клиентов» (копия db1: order_reviews живёт в курьерке).
# Строка = отзыв на заказ. order_reviews.user_id - клиент курьерки, клиент
# витрины берётся через заказ (orders.uuid = id заказа витрины).
#   * Оценка 1-5, без оценки - NULL (не входит в среднее и долю).
#   * Причина = тип ошибки, проставленный оператором (error_type); у остальных
#     «Причина не указана».
#   * Сегмент и RFM клиента - на конец выбранного периода.
_REVIEW_REASON = """CASE rv.error_type
        WHEN 'shortage' THEN 'Недовоз'
        WHEN 'wrong_item' THEN 'Пересорт'
        WHEN 'expired' THEN 'Просрочка'
        WHEN 'other' THEN 'Другое'
        ELSE 'Причина не указана'
    END"""

REVIEWS_BASE_SQL = f"""SELECT
    rv.id                                            AS review_id,
    rv.created_at                                    AS reviewed_at,
    so.user_id                                       AS client_id,
    so.id                                            AS order_id,
    CASE WHEN rv.rating BETWEEN 1 AND 5 THEN rv.rating END AS rating,
    CASE WHEN rv.rating BETWEEN 1 AND 5 THEN CAST(rv.rating AS CHAR) ELSE 'Без оценки' END AS rating_label,
    NULLIF(TRIM(rv.description), '')                 AS review_text,
    CASE WHEN NULLIF(TRIM(rv.description), '') IS NULL THEN 1 ELSE 0 END AS is_without_text,
    {_REVIEW_REASON}                                 AS reason_name,
    st.name                                          AS shop_name
FROM order_reviews rv
JOIN orders o ON o.id = rv.order_id
LEFT JOIN djami_store_copy.orders so ON so.id = CAST(o.uuid AS UNSIGNED)
LEFT JOIN djami_store_copy.stores st ON st.id = so.store_id
WHERE rv.deleted_at IS NULL
  AND COALESCE(o.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
  AND COALESCE(so.user_id, 0) NOT IN {NOT_TEST_USERS_STORE}"""

dataset(
    table_name="ops_client_reviews",
    database_uuid=DB1,
    main_dttm_col="reviewed_at",
    sql=f"""{{% set period = get_time_filter('reviewed_at', target_type='DATETIME') %}}
SELECT
    r.*,
    COALESCE(f.segment, 'Нет покупок')               AS segment,
    CONCAT(f.r_score, f.f_score, f.m_score)          AS rfm_code,
    CASE
        WHEN r.rating <= 3 THEN 'Связаться с клиентом, разобрать жалобу'
        WHEN r.rating IS NULL THEN 'Запросить оценку'
        ELSE 'Поблагодарить, предложить повторный заказ'
    END                                              AS next_step
FROM ({REVIEWS_BASE_SQL}) r
LEFT JOIN ({rfm_snapshot_sql(ASOF_EXPR, 'djami_store_copy.')}) f ON f.client_id = r.client_id
""",
    columns=[
        col("reviewed_at", "Дата", dttm=True),
        col("shop_name", "Магазин"),
        col("client_id", "ID клиента"),
        col("segment", "Сегмент"),
        col("rating_label", "Оценка"),
        col("reason_name", "Причина"),
    ],
)

# R15 «Динамика средней оценки»: отзыв повторён для группировок «Месяц» и
# «Неделя» + пустые строки на каждый интервал (без отзывов средняя = NULL,
# линия рвётся). Незавершённый интервал помечен «*».
dataset(
    table_name="ops_client_review_periods",
    database_uuid=DB1,
    main_dttm_col="reviewed_at",
    sql=f"""WITH RECURSIVE
r AS ({REVIEWS_BASE_SQL}),
months AS (
    SELECT CAST(DATE_FORMAT((SELECT MIN(reviewed_at) FROM r), '%Y-%m-01') AS DATETIME) AS ps
    UNION ALL
    SELECT ps + INTERVAL 1 MONTH FROM months WHERE ps + INTERVAL 1 MONTH <= NOW()
),
weeks AS (
    SELECT CAST(DATE_SUB(DATE((SELECT MIN(reviewed_at) FROM r)),
                         INTERVAL WEEKDAY((SELECT MIN(reviewed_at) FROM r)) DAY) AS DATETIME) AS ps
    UNION ALL
    SELECT ps + INTERVAL 1 WEEK FROM weeks WHERE ps + INTERVAL 1 WEEK <= NOW()
),
periods AS (
    SELECT 'Месяц' AS grain, ps, ps + INTERVAL 1 MONTH AS pe,
           DATE_FORMAT(ps, '%Y-%m') AS label FROM months
    UNION ALL
    SELECT 'Неделя', ps, ps + INTERVAL 1 WEEK, DATE_FORMAT(ps, '%Y-%m-%d') FROM weeks
)
SELECT
    p.grain,
    CONCAT(p.label, CASE WHEN p.pe > NOW() THEN '*' ELSE '' END) AS period_label,
    COALESCE(r.reviewed_at, p.ps)                    AS reviewed_at,
    r.review_id,
    r.rating,
    r.rating_label,
    r.reason_name,
    r.shop_name
FROM periods p
LEFT JOIN r ON r.reviewed_at >= p.ps AND r.reviewed_at < p.pe
""",
    columns=[
        col("reviewed_at", "Дата", dttm=True),
        col("grain", "Группировка"),
        col("period_label", "Период"),
        col("shop_name", "Магазин"),
        col("rating_label", "Оценка"),
        col("reason_name", "Причина"),
    ],
)


# R16 «География заказов» (копия db2 + зоны и время курьера из копии db1).
# Строка = курьерский заказ (доставлен или отменён) × тип точки: «Доставка»
# (адрес, округлён до 3 знаков ≈ 100 м: квартиры на карте не видны) и
# «Магазин» (точка отправления). KPI, бары и таблица берут только «Доставку».
#   * Район = зона доставки курьерки на момент заказа (orders.zone_id).
#   * Дальность = расстояние по прямой магазин -> адрес, км.
#   * Время доставки = принял -> выдал (delivery_orders), как во вкладке «Курьеры».
GEO_ORDERS_SQL = f"""SELECT
        so.id                                        AS order_id,
        so.created_at                                AS ordered_at,
        st.name                                      AS shop_name,
        st.latitude                                  AS store_lat,
        st.longitude                                 AS store_lon,
        ROUND(a.latitude, 3)                         AS dest_lat,
        ROUND(a.longitude, 3)                        AS dest_lon,
        COALESCE(z.name, 'Зона не определена')       AS district,
        CASE WHEN a.latitude IS NOT NULL AND st.latitude IS NOT NULL
             THEN ST_Distance_Sphere(POINT(st.longitude, st.latitude), POINT(a.longitude, a.latitude)) / 1000
        END                                          AS distance_km,
        CASE WHEN so.status = 'delivered' AND TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) >= 60
             THEN TIMESTAMPDIFF(SECOND, d.created_at, d.finished_at) / 60 END AS delivery_minutes,
        so.total                                     AS total,
        CASE WHEN so.status = 'delivered' THEN 1 ELSE 0 END AS is_delivered,
        CASE WHEN so.status = 'cancelled' THEN 1 ELSE 0 END AS is_cancelled,
        CASE WHEN a.latitude IS NOT NULL AND a.latitude <> 0 THEN 1 ELSE 0 END AS has_coords
    FROM orders so
    LEFT JOIN stores st ON st.id = so.store_id
    LEFT JOIN order_addresses a ON a.id = (
        SELECT MAX(a2.id) FROM order_addresses a2 WHERE a2.order_id = so.id
    )
    LEFT JOIN lastochka_djami_copy.orders co
           ON co.uuid = CAST(so.id AS CHAR) COLLATE utf8mb4_unicode_ci AND co.parent_order_id IS NULL
    LEFT JOIN lastochka_djami_copy.shop_zones z ON z.id = co.zone_id
    LEFT JOIN lastochka_djami_copy.delivery_orders d ON d.id = (
        SELECT MAX(d2.id) FROM lastochka_djami_copy.delivery_orders d2
        WHERE d2.order_id = co.id AND d2.deleted_at IS NULL
    )
    WHERE so.deleted_at IS NULL
      AND so.shipping_method = 'courier'
      AND so.status IN ('delivered', 'cancelled')
      AND COALESCE(so.user_id, 0) NOT IN {NOT_TEST_USERS_STORE}"""

dataset(
    table_name="ops_order_geo_points",
    database_uuid=DB2,
    main_dttm_col="ordered_at",
    sql=f"""SELECT
    'Доставка' AS point_type, g.order_id, g.ordered_at, g.shop_name, g.district,
    g.dest_lat AS lat, g.dest_lon AS lon,
    g.distance_km, g.delivery_minutes, g.total, g.is_delivered, g.is_cancelled, g.has_coords
FROM ({GEO_ORDERS_SQL}) g
UNION ALL
SELECT
    'Магазин', g.order_id, g.ordered_at, g.shop_name, g.shop_name,
    g.store_lat, g.store_lon,
    NULL, g.delivery_minutes, g.total, g.is_delivered, g.is_cancelled, 1
FROM ({GEO_ORDERS_SQL}) g
""",
    columns=[
        col("ordered_at", dttm=True),
        col("point_type", "Тип точки"),
        col("shop_name", "Магазин"),
        col("district", "Район / зона"),
        col("lat", "Широта"),
        col("lon", "Долгота"),
    ],
)

# R16 «Откуда и куда»: строка = магазин × район за период, цель дуги = центр
# доставок района (средние координаты адресов). Топ-200 по заказам.
dataset(
    table_name="ops_order_geo_flows",
    database_uuid=DB2,
    main_dttm_col="ordered_at",
    sql=f"""{{% set period = get_time_filter('ordered_at', remove_filter=True, target_type='DATETIME') %}}
SELECT
    MAX(g.ordered_at)                                AS ordered_at,
    g.shop_name,
    g.district,
    MAX(g.store_lat)                                 AS source_lat,
    MAX(g.store_lon)                                 AS source_lon,
    AVG(g.dest_lat)                                  AS target_lat,
    AVG(g.dest_lon)                                  AS target_lon,
    COUNT(*)                                         AS orders,
    AVG(g.delivery_minutes)                          AS avg_delivery_minutes
FROM ({GEO_ORDERS_SQL}) g
WHERE g.is_delivered = 1
  AND g.has_coords = 1
  {period_where('g.ordered_at')}
GROUP BY g.shop_name, g.district
ORDER BY orders DESC
LIMIT 200
""",
    columns=[
        col("ordered_at", dttm=True),
        col("shop_name", "Магазин"),
        col("district", "Район / зона"),
    ],
)


# ===========================================================================
# Вкладка «Качество данных» (копия db1)
# ===========================================================================
# Строка = проверка ТЗ (раздел 6.6) за выбранный период и магазин (оба внутри
# SQL). status: «Без ошибок» / «Нужна проверка». copy_age_hours - сколько
# часов назад обновлена копия БД (таблицы копии пересоздаются ночью, время
# создания = время обновления).
#   1. Уникальность: дубли номера заказа витрины (orders.uuid) среди заказов.
#   2. Временная последовательность: сборка или доставка закончилась раньше,
#      чем началась.
#   3. Суммы: итог заказа <> позиции - скидка - бонусы + доставка + сборка
#      (расхождение больше 1 ₽, доставленные заказы).
#   4. Жалобы без заказа: отзыв ссылается на несуществующий заказ.
#   5. Зона не определена: курьерский заказ без зоны (список).
#   6. Причина замены не указана: доля замен без причины, норма < 5%.
#   7. Неполные факты инкассации: доставлен за наличные, оплата не отмечена (список).
def _dq_row(order: int, name: str, what: str, expected: str, value_sql: str,
            label_sql: str, ok_sql: str) -> str:
    return f"""SELECT {order} AS check_order, '{name}' AS check_name, '{what}' AS check_what,
       v.value AS result_value, {label_sql} AS result_label, '{expected}' AS expected,
       CASE WHEN {ok_sql} THEN 'Без ошибок' ELSE 'Нужна проверка' END AS status
FROM ({value_sql}) v"""


_DQ_ORDERS = f"""orders o LEFT JOIN shops s ON s.id = o.shop_id
    WHERE o.deleted_at IS NULL AND o.parent_order_id IS NULL
      AND COALESCE(o.client_id, 0) <> {NOT_TEST_CLIENT_COURIER}
      {period_where('o.created_at')}
      {shops_where('s.title')}"""

_DQ_ROWS = [
    _dq_row(1, "Уникальность заказов и событий", "Дубли номера заказа (orders.uuid)", "0",
            f"SELECT COUNT(*) AS value FROM (SELECT o.uuid FROM {_DQ_ORDERS} GROUP BY o.uuid HAVING COUNT(*) > 1) d",
            "CAST(v.value AS CHAR)", "v.value = 0"),
    _dq_row(2, "Временная последовательность", "Сборка или доставка закончилась раньше начала", "0",
            f"""SELECT
                (SELECT COUNT(*) FROM assemblies a JOIN orders ao ON ao.id = a.order_id
                   JOIN orders o ON o.id = COALESCE(ao.parent_order_id, ao.id) LEFT JOIN shops s ON s.id = o.shop_id
                  WHERE a.deleted_at IS NULL AND a.finished_at < a.created_at
                    {period_where('o.created_at')} {shops_where('s.title')})
              + (SELECT COUNT(*) FROM delivery_orders d JOIN orders o ON o.id = d.order_id LEFT JOIN shops s ON s.id = o.shop_id
                  WHERE d.deleted_at IS NULL
                    AND (d.finished_at < d.created_at OR d.started_at < d.created_at OR d.finished_at < d.started_at)
                    {period_where('o.created_at')} {shops_where('s.title')}) AS value""",
            "CAST(v.value AS CHAR)", "v.value = 0"),
    _dq_row(3, "Суммы заказов", "Итог ≠ позиции − скидка − бонусы + доставка + сборка", "0",
            f"""SELECT COUNT(*) AS value FROM {_DQ_ORDERS}
                  AND o.status_id = 5 AND o.items_subtotal IS NOT NULL
                  AND ABS(o.amount - (o.items_subtotal - COALESCE(o.discount, 0) - COALESCE(o.bonus_spent, 0)
                          + COALESCE(o.delivery_price, 0) + COALESCE(o.assembly_cost, 0))) > 1""",
            "CAST(v.value AS CHAR)", "v.value = 0"),
    _dq_row(4, "Жалобы без заказа", "Отзыв ссылается на несуществующий заказ", "0",
            f"""SELECT COUNT(*) AS value FROM order_reviews r LEFT JOIN orders o ON o.id = r.order_id
                WHERE r.deleted_at IS NULL AND o.id IS NULL
                  {period_where('r.created_at')}""",
            "CAST(v.value AS CHAR)", "v.value = 0"),
    _dq_row(5, "Зона не определена", "Курьерские заказы без подтверждённой зоны", "Список",
            f"SELECT COUNT(*) AS value FROM {_DQ_ORDERS} AND o.type_id = 2 AND o.zone_id IS NULL",
            "CONCAT(v.value, ' заказ(ов)')", "v.value = 0"),
    _dq_row(6, "Причина замены не указана", "Доля замен без причины", "< 5%",
            f"""SELECT 100 * SUM(CASE WHEN oi.replace_reason_id IS NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS value
                FROM order_items oi JOIN assemblies a ON a.order_id = oi.order_id AND a.deleted_at IS NULL
                JOIN orders so ON so.id = oi.order_id
                JOIN orders o ON o.id = COALESCE(so.parent_order_id, so.id)
                LEFT JOIN shops s ON s.id = o.shop_id
                WHERE oi.deleted_at IS NULL AND oi.origin_product_id IS NOT NULL
                  AND oi.origin_product_id <> oi.product_id
                  {period_where('o.created_at')} {shops_where('s.title')}""",
            "CONCAT(REPLACE(FORMAT(COALESCE(v.value, 0), 1), '.', ','), '%')", "COALESCE(v.value, 0) < 5"),
    _dq_row(7, "Неполные факты инкассации", "Доставлен за наличные, оплата не отмечена", "Список",
            f"""SELECT COUNT(*) AS value FROM {_DQ_ORDERS}
                  AND o.status_id = 5 AND o.payment_type_id = 2 AND COALESCE(o.payment_status, '') <> 'paid'""",
            "CONCAT(v.value, ' заказ(ов)')", "v.value = 0"),
]

dataset(
    table_name="ops_dq_checks",
    database_uuid=DB1,
    main_dttm_col="checked_at",
    sql="""{% set period = get_time_filter('checked_at', remove_filter=True, target_type='DATETIME') %}
{% set shops = filter_values('shop_name', remove_filter=True) %}
SELECT
    c.*,
    NOW()                                            AS checked_at,
    {% if shops %}'Выбранные магазины'{% else %}'Все магазины'{% endif %} AS shop_name,
    fr.copy_refreshed_at,
    TIMESTAMPDIFF(MINUTE, fr.copy_refreshed_at, NOW()) / 60 AS copy_age_hours
FROM (
""" + "\nUNION ALL\n".join(_DQ_ROWS) + """
) c
CROSS JOIN (
    SELECT MAX(create_time) AS copy_refreshed_at
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
) fr
""",
    columns=[
        col("checked_at", dttm=True),
        col("shop_name", "Магазин"),
        col("check_name", "Проверка"),
        col("check_what", "Что проверяем"),
        col("result_label", "Результат"),
        col("expected", "Норма"),
        col("status", "Статус"),
    ],
)


# ===========================================================================
# Запись YAML
# ===========================================================================

def emit_all() -> list[pathlib.Path]:
    written = []
    for d in DATASETS:
        out_dir = DATASETS_DIR / DB_DIR[d["database_uuid"]]
        out_dir.mkdir(parents=True, exist_ok=True)
        doc = {
            "table_name": d["table_name"],
            "main_dttm_col": d["main_dttm_col"],
            "database_uuid": d["database_uuid"],
            "schema": None,
            "sql": d["sql"],
            "uuid": d["uuid"],
            "columns": d["columns"],
            "metrics": [],
            "version": "1.0.0",
        }
        path = out_dir / f"{d['table_name']}.yaml"
        header = (
            "# Сгенерировано scripts/ops_datasets.py («Операционные отчёты», ТЗ v2.1).\n"
            "# Не править руками: описание правил расчёта - в генераторе.\n"
        )
        path.write_text(
            header + yaml.safe_dump(doc, allow_unicode=True, sort_keys=False, width=1000),
            encoding="utf-8",
        )
        written.append(path)
    return written


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.parse_args()
    for p in emit_all():
        print(p.relative_to(ROOT))


if __name__ == "__main__":
    main()
