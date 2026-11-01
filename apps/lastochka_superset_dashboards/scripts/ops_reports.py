#!/usr/bin/env python
"""Чарты и раскладка «Операционных отчётов» по ТЗ Магомеда v2.1 (21.10.2026).

ТЗ задаёт экран как контракт: 5 вкладок, 16 отчётов, у каждого позиции
П1-П7 (шапка, KPI, два-три чарта, таблица, примечание, методика), конкретный
плагин Superset, заголовок и подзаголовок панели. Этот модуль описывает каждый
отчёт декларативно и генерирует:
  * superset_assets/charts/Ops_*.yaml - чарты (uuid5 от ключа, стабильны);
  * OPS_DASHBOARD - манифест дашборда для build_dashboards.py (layout "reports").

Запуск: python scripts/ops_reports.py   (после него python scripts/build_dashboards.py)

Соответствие плагинов ТЗ (раздел 7) и viz_type Superset 6.1:
  Big Number                 -> big_number_total
  ECharts Bar Chart          -> echarts_timeseries_bar с категориальной осью X
  ECharts Line Chart         -> echarts_timeseries_line с категориальной осью X
  ECharts Pie Chart (donut)  -> pie, donut = true
  Pivot Table v2             -> pivot_table_v2
  Table                      -> table (новый плагин)
  deck.gl Scatterplot / Arc  -> deck_scatter / deck_arc (подложка OpenStreetMap
                                без ключа, стандартная в Superset)
Подзаголовок панели = описание чарта, раскрытое на дашборде (expanded_slices).
"""
from __future__ import annotations

import pathlib
import uuid

import yaml

import ops_datasets as dsm

ROOT = pathlib.Path(__file__).resolve().parent.parent
CHARTS_DIR = ROOT / "superset_assets" / "charts"

NS = uuid.UUID("6a1b3f0e-2c4d-4e5f-8a9b-0c1d2e3f4a5b")

DS_UUID = {d["table_name"]: d["uuid"] for d in dsm.DATASETS}
DS_DTTM = {d["table_name"]: d["main_dttm_col"] for d in dsm.DATASETS}
DS_VERBOSE = {
    d["table_name"]: {c["column_name"]: c.get("verbose_name") for c in d["columns"]}
    for d in dsm.DATASETS
}


def labeled_column(ds: str, column: str) -> dict:
    """Adhoc-колонка с русской подписью: Pivot Table v2 в Superset 6.1 выводит в
    заголовках имя колонки, а не verbose_name датасета."""
    return {
        "expressionType": "SQL",
        "sqlExpression": column,
        "label": DS_VERBOSE[ds].get(column) or column,
    }

# Форматы чисел (D3_FORMAT в superset_config: пробел тысяч, запятая дроби,
# валюта « ₽» суффиксом): рубли без копеек, проценты и минуты с одним знаком.
RUB = "$,.0f"
INT = ",d"
NUM1 = ",.1f"
NUM2 = ",.2f"
PCT = ".1%"

# Палитра ТЗ (п. 3.8) как своя схема Superset: EXTRA_CATEGORICAL_COLOR_SCHEMES в
# superset_config.py (dev и прод), id "lastochkaOps". Первый цвет = основная серия.
COLOR_SCHEME = "lastochkaOps"
PALETTE = ["#1FA8C9", "#454E7C", "#5AC189", "#FF9754", "#E94359", "#A2C7D3"]
ACCENT = "#087E91"
OSM_TILES = "https://tile.openstreetmap.org/{z}/{x}/{y}.png"
# Центр Нальчика: стартовый вид карты до автоподгонки по точкам.
# Фиксированный вид на Нальчик: при autozoom Superset 6.1 на первом рендере не
# запрашивает тайлы подложки (карта без улиц), поэтому вид задан явно.
NALCHIK_VIEWPORT = {"latitude": 43.49, "longitude": 43.62, "zoom": 12.3, "bearing": 0, "pitch": 0}

NO_DATA = "'Нет данных'"

# CSS дашборда.
# 1. Подзаголовок панели. Superset 6.1 раскрытое описание чарта не рисует (API
#    отдаёт description_markeddown, фронт ждёт description_markdown), но кладёт
#    описание в aria-label контейнера графика: выводим его под заголовком, а шапке
#    добавляем отступ - высота графика пересчитывается от высоты шапки.
# 2. Первый KPI отчёта - акцентный цвет (п. 3.8 ТЗ): первая карточка второй строки
#    вкладки отчёта (первая строка - шапка П1).
# 3. Подпись KPI одного размера во всех карточках (Big Number подгоняет шрифт
#    под длину текста).
DASHBOARD_CSS = f"""
.chart-slice {{ position: relative; }}
.chart-slice:has(> .dashboard-chart[aria-label]:not([aria-label=""])) > div:first-child {{ padding-bottom: 20px; }}
.chart-slice > .dashboard-chart[aria-label]:not([aria-label=""]) {{ position: static; }}
.chart-slice > .dashboard-chart[aria-label]:not([aria-label=""])::before {{
  content: attr(aria-label); position: absolute; top: 31px; left: 0; right: 0;
  font-size: 12px; line-height: 16px; color: #6b6b6b;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}}
.dashboard-component-tabs-content > .dragdroppable-row:nth-child(2) .grid-row > .dragdroppable-column:first-child [data-test-viz-type="big_number_total"] .header-line {{
  color: {ACCENT} !important;
}}
[data-test-viz-type="big_number_total"] .subheader-line {{ font-size: 12px !important; height: auto !important; color: #6b6b6b; }}
"""


def chart_uuid(key: str) -> str:
    return str(uuid.uuid5(NS, f"ops-chart:{key}"))


def metric(sql: str, label: str) -> dict:
    return {
        "expressionType": "SQL",
        "sqlExpression": sql,
        "label": label,
        "hasCustomLabel": True,
    }


def time_filter(ds: str) -> dict:
    return {
        "clause": "WHERE",
        "comparator": "No filter",
        "expressionType": "SIMPLE",
        "operator": "TEMPORAL_RANGE",
        "subject": DS_DTTM[ds],
    }


def where(sql: str) -> dict:
    return {"clause": "WHERE", "expressionType": "SQL", "sqlExpression": sql}


def having(sql: str) -> dict:
    return {"clause": "HAVING", "expressionType": "SQL", "sqlExpression": sql}


def _filters(ds: str, extra) -> list[dict]:
    out = [time_filter(ds)] if DS_DTTM.get(ds) else []
    for f in extra or ():
        out.append(f if isinstance(f, dict) else where(f))
    return out


def _chart(key, title, ds, params, subtitle="", kind="chart") -> dict:
    return {
        "key": key,
        "uuid": chart_uuid(key),
        "title": title,
        "subtitle": subtitle,
        "dataset": ds,
        "params": params,
        "kind": kind,
    }


# ---------------------------------------------------------------------------
# Плагины
# ---------------------------------------------------------------------------

def kpi(key, title, ds, sql, fmt, caption, filters=()) -> dict:
    """Big Number: значение + подпись, без тренда."""
    return _chart(key, title, ds, {
        "viz_type": "big_number_total",
        "metric": metric(sql, title),
        "adhoc_filters": _filters(ds, filters),
        "subheader": caption,
        "header_font_size": 0.4,
        "subheader_font_size": 0.15,
        "y_axis_format": fmt,
        "time_format": "smart_date",
    }, kind="kpi")


def _axes(cat_title: str, val_title: str) -> dict:
    return {
        "x_axis_title": cat_title,
        "x_axis_title_margin": 30,
        "y_axis_title": val_title,
        "y_axis_title_margin": 50,
        "y_axis_title_position": "Left",
    }


def hbar(key, title, subtitle, ds, dim, sql, label, fmt, limit=None, filters=(),
         cat_title="", val_title="", sort="metric_desc", groupby=None) -> dict:
    """ECharts Bar Chart: горизонтальные бары по категории, подписи значений."""
    m = metric(sql, label)
    params = {
        "viz_type": "echarts_timeseries_bar",
        "x_axis": dim,
        "metrics": [m],
        "groupby": list(groupby or []),
        "adhoc_filters": _filters(ds, filters),
        "orientation": "horizontal",
        "row_limit": limit or 1000,
        "show_value": True,
        "show_legend": bool(groupby),
        "legendOrientation": "bottom",
        "legendType": "scroll",
        "color_scheme": COLOR_SCHEME,
        "y_axis_format": fmt,
        "truncateYAxis": False,
        "zoomable": False,
        "rich_tooltip": True,
        "stack": None,
        "only_total": True,
        "comparison_type": "values",
        "order_desc": True,
        # подпись категориальной оси у горизонтальных баров налезает на длинные
        # названия (товары, зоны): категория ясна из заголовка панели
        **_axes("", val_title),
    }
    if sort == "metric_desc":
        # ECharts рисует первую категорию горизонтального бара внизу. Когда
        # выводятся все категории, сортируем по возрастанию: самый большой бар
        # оказывается сверху. Для топ-N (limit < 30) нужен ORDER BY ... DESC
        # LIMIT N, иначе запрос отберёт N самых маленьких: крупнейший внизу.
        params["x_axis_sort"] = label
        params["x_axis_sort_asc"] = not limit or limit >= 30
    elif sort == "label_asc":
        params["x_axis_sort"] = dim
        params["x_axis_sort_asc"] = True
    elif sort == "label_desc":
        params["x_axis_sort"] = dim
        params["x_axis_sort_asc"] = False
    return _chart(key, title, ds, params, subtitle)


def vbar(key, title, subtitle, ds, dim, metrics, fmt, filters=(),
         cat_title="", val_title="") -> dict:
    """ECharts Bar Chart: сгруппированные вертикальные бары (несколько метрик)."""
    return _chart(key, title, ds, {
        "viz_type": "echarts_timeseries_bar",
        "x_axis": dim,
        "metrics": [metric(s, l) for s, l in metrics],
        "groupby": [],
        "adhoc_filters": _filters(ds, filters),
        "orientation": "vertical",
        "row_limit": 1000,
        "show_value": True,
        "show_legend": True,
        "legendOrientation": "bottom",
        "legendType": "scroll",
        "color_scheme": COLOR_SCHEME,
        "y_axis_format": fmt,
        "x_axis_sort": dim,
        "x_axis_sort_asc": True,
        "zoomable": False,
        "rich_tooltip": True,
        "stack": None,
        "comparison_type": "values",
        **_axes(cat_title, val_title),
    }, subtitle)


def line(key, title, subtitle, ds, x, metrics, fmt, filters=(), time_grain=None,
         cat_title="", val_title="", y_bounds=(None, None)) -> dict:
    """ECharts Line Chart: 1-3 серии, точки на значениях, без сглаживания."""
    params = {
        "viz_type": "echarts_timeseries_line",
        "x_axis": x,
        "metrics": [metric(s, l) for s, l in metrics],
        "groupby": [],
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 10000,
        "seriesType": "line",
        "markerEnabled": True,
        "markerSize": 6,
        "show_value": False,
        "show_legend": True,
        "legendOrientation": "bottom",
        "legendType": "scroll",
        "color_scheme": COLOR_SCHEME,
        "y_axis_format": fmt,
        "y_axis_bounds": list(y_bounds),
        "x_axis_sort_asc": True,
        "zoomable": False,
        "rich_tooltip": True,
        "comparison_type": "values",
        "x_axis_time_format": "%d.%m",
        **_axes(cat_title, val_title),
    }
    if time_grain:
        params["time_grain_sqla"] = time_grain
    else:
        params["x_axis_sort"] = x
    return _chart(key, title, ds, params, subtitle)


def donut(key, title, subtitle, ds, dim, sql, label, fmt, filters=()) -> dict:
    """ECharts Pie Chart (donut): легенда справа, значение и процент."""
    return _chart(key, title, ds, {
        "viz_type": "pie",
        "groupby": [dim],
        "metric": metric(sql, label),
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 6,
        "sort_by_metric": True,
        "color_scheme": COLOR_SCHEME,
        "show_labels": True,
        "labels_outside": True,
        "label_line": True,
        "label_type": "value_percent",
        "show_labels_threshold": 3,
        "number_format": fmt,
        "show_legend": True,
        "legendType": "scroll",
        "legendOrientation": "right",
        "donut": True,
        "innerRadius": 55,
        "outerRadius": 80,
        "show_total": False,
    }, subtitle)


def pivot(key, title, subtitle, ds, rows, cols, sql, label, fmt, filters=(),
          totals=True, row_order="key_a_to_z", col_order="key_a_to_z") -> dict:
    """Pivot Table v2: строки × столбцы, заливка по значению, итоги."""
    return _chart(key, title, ds, {
        "viz_type": "pivot_table_v2",
        "groupbyRows": [labeled_column(ds, rows)],
        "groupbyColumns": [labeled_column(ds, cols)],
        "metrics": [metric(sql, label)],
        "metricsLayout": "COLUMNS",
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 10000,
        "aggregateFunction": "Sum",
        "rowTotals": totals,
        "colTotals": totals,
        "rowSubTotals": False,
        "colSubTotals": False,
        "transposePivot": False,
        "combineMetric": False,
        "valueFormat": fmt,
        "rowOrder": row_order,
        "colOrder": col_order,
        "date_format": "smart_date",
        "conditional_formatting": [
            {"colorScheme": "#1FA8C9", "column": label, "operator": "≥", "targetValue": 0}
        ],
    }, subtitle)


def table(key, title, subtitle, ds, groupby, metrics, sort_label=None, filters=(),
          totals=False, cell_bars=False) -> dict:
    """Table (агрегированная): поиск, сортировка, 12 строк на странице, CSV.

    metrics: список (sql, label, d3-формат). Текстовая «метрика»
    (MAX('Нет данных'), MAX(действие)) нужна, чтобы колонка стояла на своём
    месте после группировочных."""
    ms = [metric(s, l) for s, l, _ in metrics]
    column_config = {}
    for _, l, f in metrics:
        if f:
            column_config[l] = {"d3NumberFormat": f, "horizontalAlign": "right"}
    params = {
        "viz_type": "table",
        "query_mode": "aggregate",
        "groupby": list(groupby),
        "metrics": ms,
        "percent_metrics": [],
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 10000,
        "server_pagination": False,
        "page_length": 12,
        "include_search": True,
        "show_cell_bars": cell_bars,
        "show_totals": totals,
        "allow_rearrange_columns": False,
        "allow_render_html": False,
        "table_timestamp_format": "%d.%m.%Y",
        "color_pn": False,
        "order_desc": True,
        "column_config": column_config,
    }
    if sort_label:
        sm = next(m for m in ms if m["label"] == sort_label)
        params["timeseries_limit_metric"] = sm
    return _chart(key, title, ds, params, subtitle, kind="table")


def table_raw(key, title, subtitle, ds, columns, order_by=None, desc=True,
              filters=()) -> dict:
    """Table (строки датасета как есть): точный порядок колонок из ТЗ.

    columns: список (колонка, d3-формат или None)."""
    column_config = {}
    for c, f in columns:
        if f:
            column_config[c] = {"d3NumberFormat": f, "horizontalAlign": "right"}
    params = {
        "viz_type": "table",
        "query_mode": "raw",
        "all_columns": [c for c, _ in columns],
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 10000,
        "server_pagination": False,
        "page_length": 12,
        "include_search": True,
        "show_cell_bars": False,
        "allow_rearrange_columns": False,
        "allow_render_html": False,
        "table_timestamp_format": "%d.%m.%Y",
        "color_pn": False,
        "column_config": column_config,
    }
    if order_by:
        params["order_by_cols"] = [f'["{order_by}", {"false" if desc else "true"}]']
    return _chart(key, title, ds, params, subtitle, kind="table")


def scatter(key, title, subtitle, ds, filters=()) -> dict:
    """deck.gl Scatterplot: lat/lon, размер = число заказов, цвет = магазин."""
    return _chart(key, title, ds, {
        "viz_type": "deck_scatter",
        "spatial": {"type": "latlong", "latCol": "lat", "lonCol": "lon"},
        "point_radius_fixed": {
            "type": "metric",
            "value": metric("COUNT(DISTINCT order_id)", "Заказов"),
        },
        "point_unit": "radius_m",
        "multiplier": 20,
        "min_radius": 6,
        "max_radius": 40,
        "mapbox_style": OSM_TILES,
        "autozoom": False,
        "viewport": NALCHIK_VIEWPORT,
        "color_scheme_type": "categorical_palette",
        "dimension": "shop_name",
        "color_scheme": COLOR_SCHEME,
        "color_picker": {"r": 31, "g": 168, "b": 201, "a": 0.75},
        "opacity": 75,
        "tooltip_contents": ["point_type", "district"],
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 5000,
        "filter_nulls": True,
        "legend_position": "tr",
        "legend_format": None,
    }, subtitle, kind="map")


def arc(key, title, subtitle, ds, filters=()) -> dict:
    """deck.gl Arc: магазин -> район, цвет = магазин, топ-200 потоков."""
    return _chart(key, title, ds, {
        "viz_type": "deck_arc",
        "start_spatial": {"type": "latlong", "latCol": "source_lat", "lonCol": "source_lon"},
        "end_spatial": {"type": "latlong", "latCol": "target_lat", "lonCol": "target_lon"},
        "stroke_width": 4,
        "mapbox_style": OSM_TILES,
        "autozoom": False,
        "tooltip_contents": ["district", "orders", "avg_delivery_minutes"],
        "viewport": NALCHIK_VIEWPORT,
        "color_scheme_type": "categorical_palette",
        "dimension": "shop_name",
        "color_scheme": COLOR_SCHEME,
        "color_picker": {"r": 31, "g": 168, "b": 201, "a": 0.6},
        "target_color_picker": {"r": 69, "g": 78, "b": 124, "a": 0.6},
        "adhoc_filters": _filters(ds, filters),
        "row_limit": 200,
        "filter_nulls": True,
        "legend_position": "tr",
    }, subtitle)


# ---------------------------------------------------------------------------
# Отчёт = позиции П1-П7
# ---------------------------------------------------------------------------

COPY_REFRESH = "копия БД, обновляется раз в сутки ночью (около 03:30 МСК)"
LIVE_REFRESH = "живая база, без суточной задержки"

REPORTS: list[dict] = []


def report(code, tab, title, priority, source, refresh, kpis, rows, note, method,
           table=None, table_tabs=None) -> dict:
    r = {
        "code": code,
        "tab": tab,
        "title": title,
        "priority": priority,
        "source": source,
        "refresh": refresh,
        "kpis": kpis,
        "rows": rows,
        "table": table,
        "table_tabs": table_tabs,
        "note": note,
        "method": method,
    }
    REPORTS.append(r)
    return r


# ===========================================================================
# Товары
# ===========================================================================
L = "ops_product_losses"
A = "ops_assembly_items"
ABC = "ops_abc_products"

report(
    "R1", "Товары", "Потери на товарах", "P1",
    "ops_product_losses (витрина ТЗ dm_demand_loss_daily)", COPY_REFRESH,
    kpis=[
        kpi("r1_k1", "Упущенная выручка", L, "SUM(loss_amount)", RUB,
            "Оценка по поиску, замены дешевле и отказы, ₽"),
        kpi("r1_k2", "Запросы без результата", L, "COUNT(DISTINCT search_id)", INT,
            "Итоговые поиски: пусто или нет в наличии, шт."),
        kpi("r1_k3", "Позиций с заменой", L, "SUM(is_replaced)", INT,
            "Заменено при сборке, шт."),
        kpi("r1_k4", "Потери на заменах", L, "SUM(price_delta_amount + refused_amount)", RUB,
            "Замена дешевле и отказы, ₽"),
    ],
    rows=[[
        (line("r1_p3", "Динамика потерь", "Оценка и подтверждённые потери · ₽", L, "event_at",
              [("SUM(search_amount)", "Оценка по событиям"),
               ("SUM(price_delta_amount + refused_amount)", "Подтверждённые потери")],
              RUB, time_grain="P1D", cat_title="Дата", val_title="Потери, ₽"), 6),
        (donut("r1_p4", "Что формирует потери", "Структура упущенной выручки", L, "loss_kind",
               "SUM(loss_amount)", "Потери, ₽", RUB,
               filters=["loss_kind IN ('Товар не найден · оценка', 'Отказ от замены', 'Замена дешевле')"]), 6),
    ]],
    table_tabs=[
        ("По товарам", table(
            "r1_p5a", "Товары по объёму потерь", "", L, ["product_name"],
            [("SUM(loss_amount)", "Всего потерь, ₽", RUB),
             ("SUM(is_search_unavailable)", "Не найдено, запросов", INT),
             ("SUM(price_delta_amount)", "Разница цены, ₽", RUB),
             ("SUM(is_refused)", "Отказы, позиций", INT),
             ("SUM(is_replaced) / NULLIF(SUM(is_position), 0)", "Доля замен", PCT),
             ("SUM(loss_amount) / NULLIF(SUM(SUM(loss_amount)) OVER (), 0)", "Доля потерь", PCT),
             (f"COALESCE(MAX(margin_lost), {NO_DATA})", "Упущенная маржа", None)],
            sort_label="Всего потерь, ₽", filters=["product_name IS NOT NULL"])),
        ("Запросы без привязки", table(
            "r1_p5b", "Запросы без привязки", "", L, ["search_query"],
            [("COUNT(DISTINCT search_id)", "Запросов", INT),
             ("COUNT(DISTINCT client_key)", "Клиентов", INT),
             ("DATE_FORMAT(MAX(event_at), '%d.%m.%Y')", "Последний раз", None)],
            sort_label="Запросов", filters=["loss_kind = 'Запрос без привязки'"])),
    ],
    note="«Не найдено» оценивается по текущей цене товара: история цен не хранится. "
         "Себестоимости в системе нет, поэтому упущенная маржа недоступна.",
    method="""**Упущенная выручка** = оценка по поиску + Σ(цена запрошенного − цена замены) + полная стоимость отказов.

* **Оценка по поиску.** Итоговый запрос серии (поиск срабатывает на каждую букву, считается самый длинный запрос пользователя в окне 60 с), в котором товар есть в каталоге магазина, но без остатка. Один запрос = одна несостоявшаяся покупка по текущей цене; если без остатка нашлось несколько товаров (берутся первые 5 по релевантности), цена делится между ними поровну. Лог поиска ведётся с 20.10.2026.
* **Замена дешевле.** Замена при сборке, после которой позиция стоит меньше: разница цены запрошенного товара и замены с учётом количества.
* **Отказ от замены.** Позиция убрана из заказа без замены: полная сумма позиции.
* **Запросы без привязки.** Итоговый запрос с пустой выдачей: товара нет в каталоге, привязать к товару нельзя.
* **Доля замен** = позиции товара с заменой / все позиции товара на сборке. **Доля потерь** = потери товара / все потери.

Источник: лог поиска витрины `search_query_logs`, позиции сборки `order_items` + `assemblies`.""",
)

report(
    "R2", "Товары", "Частота замен", "P1",
    "ops_assembly_items (витрина ТЗ dm_substitution_daily)", COPY_REFRESH,
    kpis=[
        kpi("r2_k1", "Заменено позиций", A, "SUM(is_replaced)", INT,
            "Позиции с заменой при сборке, шт."),
        kpi("r2_k2", "Заказы с заменой", A, "COUNT(DISTINCT replaced_delivered_order_id)", INT,
            "Доставленные заказы, где была замена"),
        kpi("r2_k3", "Согласовано", A, "SUM(is_agreed) / NULLIF(SUM(is_replaced), 0)", PCT,
            "% замен, где клиент просил позвонить"),
        kpi("r2_k4", "Без согласования", A, "SUM(is_no_offer)", INT,
            "Замены без запроса согласия (no_offer), шт."),
    ],
    rows=[[
        (pivot("r2_p3", "На что заменяют", "Матрица: оригинал → замена, все пары", A,
               "product_name", "replacement_product_name", "SUM(is_replaced)", "Замен", INT,
               filters=["is_replaced = 1"]), 6),
        (hbar("r2_p4", "Причины замен", "Количество позиций", A, "replace_reason_name",
              "SUM(is_replaced)", "Позиций", INT, limit=5, filters=["is_replaced = 1"],
              cat_title="Причина", val_title="Позиций, шт."), 6),
    ]],
    table=table(
        "r2_p5", "Детализация показателей", "", A, ["product_name"],
        [("SUM(is_replaced)", "Замен", INT),
         ("COUNT(DISTINCT CASE WHEN is_replaced = 1 THEN order_id END)", "Заказов", INT),
         ("COUNT(DISTINCT replaced_delivered_order_id) / NULLIF(MAX(period_delivered_orders), 0)", "% всех заказов", PCT),
         ("COUNT(DISTINCT CASE WHEN is_replaced = 1 THEN order_id END) / NULLIF(COUNT(DISTINCT order_id), 0)", "% по товару", PCT),
         ("SUM(is_agreed)", "Согласовано", INT),
         ("SUM(is_no_offer)", "Без предложения", INT),
         ("AVG(price_delta)", "Средняя Δ цены, ₽", RUB)],
        sort_label="Замен", filters=[having("SUM(is_replaced) > 0")]),
    note="Причину замены мобильное приложение сборщика пока не передаёт: почти все замены «Причина не указана». "
         "Факт звонка клиенту система не хранит.",
    method="""* **Замена** = сборщик положил другой товар вместо запрошенного (запрошенный товар ≠ собранный).
* **% замен** = доставленные заказы с заменой / все доставленные заказы периода. **% по товару** = заказы с заменой товара / заказы, где товар был в корзине.
* **Согласовано / без согласования.** Ответ клиента на замену не хранится. Есть только опция при оформлении «Позвонить, если товара нет в наличии»: замена в таком заказе считается согласованной (клиент просил согласовать), без опции это «без согласования» (no_offer) и отдельный тип ошибки сборки.
* **Средняя Δ цены** = цена замены − цена запрошенного товара за единицу (минус = замена дешевле).

Источник: `order_items` подзаказов сборки, `product_replace_reasons`, опции чекаута заказа.""",
)

report(
    "R3", "Товары", "Продажи и ABC", "P0",
    "ops_abc_products (витрина ТЗ dm_product_sales_abc)", COPY_REFRESH,
    kpis=[
        kpi("r3_k1", "Выручка net", ABC, "SUM(revenue)", RUB,
            "После возвратов, без доставки, ₽"),
        kpi("r3_k2", "Группа A", ABC, "COUNT(*)", INT,
            "SKU · около 80% выручки, доля в кольце", filters=["abc_class = 'A'"]),
        kpi("r3_k3", "Группа B", ABC, "COUNT(*)", INT,
            "SKU · около 15% выручки, доля в кольце", filters=["abc_class = 'B'"]),
        kpi("r3_k4", "Группа C", ABC, "COUNT(*)", INT,
            "SKU · около 5% выручки, доля в кольце", filters=["abc_class = 'C'"]),
    ],
    rows=[[
        (hbar("r3_p3", "ABC-анализ ассортимента", "Выручка и накопленная доля", ABC,
              "product_name", "SUM(revenue)", "Выручка net, ₽", RUB, limit=10,
              cat_title="Товар", val_title="Выручка, ₽"), 6),
        (donut("r3_p4", "Вклад классов в выручку", "Пороги применяются к накопленной доле", ABC,
               "abc_class", "SUM(revenue)", "Выручка net, ₽", RUB), 6),
    ]],
    table=table_raw(
        "r3_p5", "Детализация показателей", "", ABC,
        [("product_name", None), ("revenue", RUB), ("quantity", NUM1), ("orders", INT),
         ("avg_price", RUB), ("revenue_share_pct", NUM1), ("cum_share_pct", NUM1),
         ("abc_class", None), ("class_change", None)],
        order_by="revenue"),
    note="Классы пересчитываются под выбранный период и магазин; фильтры «Категория» и «Товар» только сужают показ.",
    method="""* **Выручка net** = позиции доставленных заказов минус возвращённые, без доставки (цена на момент заказа × количество).
* **ABC.** Товары по убыванию выручки: **A**: накопленная доля до 80% (товар, на котором доля переходит 80%, ещё в A), **B**: до 95%, **C**: остальное. Пороги 80/95 зашиты в датасете.
* **Смена класса**: класс в предыдущем периоде такой же длины → класс сейчас; «Новый в продажах» значит, что в прошлом периоде продаж не было.

Источник: `order_items` + `orders` (доставленные, status_id = 5).""",
)


# ===========================================================================
# Курьеры
# ===========================================================================
C = "ops_courier_orders"
CST = "ops_courier_stages"
CS = "ops_courier_stats"
CE = "ops_courier_earnings"

report(
    "R4", "Курьеры", "Производительность", "P0",
    "ops_courier_orders, ops_courier_stats (витрина ТЗ dm_courier_perf_daily)", LIVE_REFRESH,
    kpis=[
        kpi("r4_k1", "Доставлено заказов", C, "SUM(is_delivered)", INT,
            "Назначены курьерам и выданы"),
        kpi("r4_k2", "Среднее время", C, "AVG(delivery_minutes)", NUM1,
            "Принял → выдал, мин"),
        kpi("r4_k3", "Доставлено в срок", C, "SUM(is_on_time) / NULLIF(SUM(is_delivered), 0)", PCT,
            "От завершённых доставок"),
        kpi("r4_k4", "Отмены курьером", C, "SUM(is_cancelled) / NULLIF(COUNT(*), 0)", PCT,
            "% назначений"),
    ],
    rows=[[
        (hbar("r4_p3", "Время доставки по курьерам", "Среднее · принял → выдал", C,
              "courier_name", "AVG(delivery_minutes)", "Минут", NUM1, limit=5,
              cat_title="Курьер", val_title="Минут"), 6),
        (hbar("r4_p4", "Из чего складывается доставка", "Среднее время завершённых заказов", CST,
              "stage_name", "AVG(stage_minutes)", "Минут", NUM1, sort="label_asc",
              cat_title="Этап", val_title="Минут"), 6),
    ]],
    table=table_raw(
        "r4_p5", "Детализация показателей", "", CS,
        [("courier_name", None), ("delivered", INT), ("cancelled", INT), ("returned", INT),
         ("cancel_pct", NUM1), ("avg_minutes", NUM1), ("median_minutes", NUM1),
         ("p90_minutes", NUM1), ("accept_to_pickup_minutes", NUM1),
         ("pickup_to_deliver_minutes", NUM1), ("on_time_pct", NUM1),
         ("orders_per_hour", NUM1), ("orders_per_shift", NUM1), ("avg_rating", NUM2)],
        order_by="delivered"),
    note="Доставки, закрытые меньше чем через минуту после принятия, отмечены задним числом и в средние не входят. "
         "Заказов в час и за смену считаются по сменам: курьеры открывают их редко.",
    method="""* **Время** = выдал − принял (`delivery_orders.finished_at − created_at`); принял → забрал и забрал → выдал считаются по отметке «забрал» (`started_at`). Отмены и возвраты в среднее не входят; медиана и p90 считаются по заказам, а не по средним.
* **В срок** = выдан не позже конца слота доставки.
* **Отмены курьером.** Отдельного действия «курьер отказался» в системе нет: считается заказ, отменённый после назначения курьера. **Возврат** = в заказе есть возвращённая позиция.
* **Оценка** = отзыв клиента на заказ (1–5).

Источник: живая база курьерки, `delivery_orders`, `deliveries`, `orders`, `shifts`, `order_reviews`.""",
)

report(
    "R5", "Курьеры", "Оплаты и инкассация", "P1",
    "ops_courier_orders (витрина ТЗ dm_courier_finance_daily)", LIVE_REFRESH,
    kpis=[
        kpi("r5_k1", "К инкассации", C, "SUM(cash_amount)", RUB,
            "Наличные после возвратов, ₽"),
        kpi("r5_k2", "Оплата картой", C, "CAST(NULL AS DECIMAL(12, 2))", RUB,
            "Через терминал курьера: терминала нет"),
        kpi("r5_k3", "Онлайн-оплата", C, "SUM(online_amount)", RUB,
            "Картой в приложении, после возвратов, ₽"),
        kpi("r5_k4", "Известные расхождения", C, "CAST(NULL AS DECIMAL(12, 2))", RUB,
            "Сдача наличных не фиксируется"),
    ],
    rows=[[
        (donut("r5_p3", "Способы оплаты", "Сумма доставленных заказов после возвратов", C,
               "payment_method", "SUM(delivered_amount)", "Сумма, ₽", RUB,
               filters=["is_delivered = 1"]), 6),
        (hbar("r5_p4", "Неполные подтверждения", "Число фактов без подтверждения по дням", C,
              "delivered_day", "SUM(is_cash_unconfirmed)", "Заказов", INT, limit=5,
              filters=["is_cash_unconfirmed = 1"], sort="label_desc",
              cat_title="День", val_title="Заказов без отметки об оплате"), 6),
    ]],
    table=table(
        "r5_p5", "Детализация показателей", "", C, ["courier_name"],
        [("SUM(is_cash_delivered)", "Нал, заказов", INT),
         (f"MAX({NO_DATA})", "Карта, заказов", None),
         ("SUM(is_online_delivered)", "Онлайн, заказов", INT),
         ("SUM(cash_amount)", "К инкассации, ₽", RUB),
         (f"MAX({NO_DATA})", "Сдано, ₽", None),
         (f"MAX({NO_DATA})", "Расхождение, ₽", None),
         ("SUM(cash_amount) / NULLIF(SUM(is_cash_delivered), 0)", "Средний чек нал, ₽", RUB),
         ("SUM(online_amount) / NULLIF(SUM(is_online_delivered), 0)", "Средний чек онлайн, ₽", RUB),
         ("SUM(cash_amount) / NULLIF(SUM(delivered_amount), 0)", "Доля наличных", PCT)],
        sort_label="К инкассации, ₽"),
    note="Терминала у курьера нет: карта = онлайн-оплата в приложении. Факт сдачи наличных и расхождения система не хранит.",
    method="""* **К инкассации** = Σ доставленных заказов за наличные − возвращённые позиции этих заказов.
* **Онлайн-оплата**: заказы, оплаченные картой в приложении (списание через ЮKassa), после возвратов.
* **Неполное подтверждение**: доставленный заказ за наличные, по которому оплата не отмечена. Последние 5 дней с такими заказами.
* **Сдано / расхождение** = собрано − сдано. Сдача наличных не фиксируется, поэтому «Нет данных», а не ноль.

Источник: живая база курьерки, `orders.payment_type_id` (1 = карта онлайн, 2 = наличные), `payment_status`.""",
)

report(
    "R6", "Курьеры", "Зоны доставки", "P1",
    "ops_courier_orders (витрина ТЗ dm_courier_zone_daily)", LIVE_REFRESH,
    kpis=[
        kpi("r6_k1", "Назначено заказов", C, "COUNT(*)", INT, "Один заказ — одна зона"),
        kpi("r6_k2", "Активные зоны", C, "COUNT(DISTINCT zone_id)", INT, "Условные зоны доставки"),
        kpi("r6_k3", "Среднее время", C, "AVG(delivery_minutes)", NUM1, "По доставленным, мин"),
        kpi("r6_k4", "Зона не определена", C, "SUM(CASE WHEN zone_id IS NULL THEN 1 ELSE 0 END)", INT,
            "Требуют уточнения адреса"),
    ],
    rows=[[
        (table("r6_p3", "Распределение по зонам", "Условная схема · не географическая карта", C,
               ["zone_name"],
               [("COUNT(*)", "Заказы", INT),
                ("COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (), 0)", "Доля", PCT)],
               sort_label="Заказы", totals=True, cell_bars=True), 6),
        (hbar("r6_p4", "Нагрузка по зонам", "Назначенные заказы", C, "zone_name",
              "COUNT(*)", "Заказов", INT, limit=50, cat_title="Зона", val_title="Заказов"), 6),
    ]],
    table=table(
        "r6_p5", "Детализация показателей", "", C, ["zone_name"],
        [("COUNT(*)", "Заказов", INT),
         ("COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (), 0)", "Доля", PCT),
         ("AVG(delivery_minutes)", "Среднее время, мин", NUM1),
         ("SUM(is_cancelled) / NULLIF(COUNT(*), 0)", "Отмены", PCT),
         ("SUM(delivered_amount) / NULLIF(SUM(is_delivered), 0)", "Средняя выручка/заказ, ₽", RUB),
         ("COUNT(DISTINCT courier_name)", "Курьеров", INT)],
        sort_label="Заказов"),
    note="Зона берётся на момент заказа (orders.zone_id); полигонов зон в отчёте нет, поэтому распределение показано таблицей.",
    method="""* **Доля зоны** = заказы зоны / все назначенные заказы. **Отмены** = отменённые после назначения / заказы зоны.
* **Среднее время**: принял → выдал по доставленным заказам зоны.
* **Средняя выручка/заказ**: сумма доставленных заказов зоны после возвратов / доставленные заказы.

Источник: живая база курьерки, `orders.zone_id`, `shop_zones`.""",
)

report(
    "R7", "Курьеры", "Заработок", "P1",
    "ops_courier_orders, ops_courier_earnings (витрина ТЗ dm_courier_earnings)", LIVE_REFRESH,
    kpis=[
        kpi("r7_k1", "Начислено", C, "SUM(courier_earning)", RUB, "База + бонусы − удержания, ₽"),
        kpi("r7_k2", "Базовый заработок", C, "SUM(courier_earning)", RUB, "Тариф на дату доставки, ₽"),
        kpi("r7_k3", "Бонусы", C, "CAST(NULL AS DECIMAL(12, 2))", RUB, "Отдельные начисления: в системе нет"),
        kpi("r7_k4", "Среднее за заказ", C, "SUM(courier_earning) / NULLIF(SUM(is_delivered), 0)", RUB,
            "По доставленным, ₽"),
    ],
    rows=[[
        (hbar("r7_p3", "Заработок по курьерам", "Начисления за выбранный период", C,
              "courier_name", "SUM(courier_earning)", "Начислено, ₽", RUB, limit=5,
              cat_title="Курьер", val_title="Начислено, ₽"), 6),
        (hbar("r7_p4", "Базовые начисления по зонам", "С учётом версий тарифов", C,
              "zone_tariff_label", "SUM(courier_earning)", "Начислено, ₽", RUB, limit=60,
              filters=["is_delivered = 1"], cat_title="Зона · тариф", val_title="Начислено, ₽"), 6),
    ]],
    table=table_raw(
        "r7_p5", "Начисления по курьерам и зонам", "", CE,
        [("courier_name", None), ("zone_name", None), ("delivered", INT), ("zone_tariff", RUB),
         ("base_amount", RUB), ("bonus_amount", RUB), ("deduction_amount", RUB),
         ("total_amount", RUB)],
        order_by="total_amount"),
    note="Тариф курьера = цена доставки зоны; бесплатная для клиента доставка оплачивается курьеру по цене зоны. "
         "До 23.09.2026 снимка цены нет, тариф взят по текущей цене зоны (приблизительно).",
    method="""* **Итого** = Σ(заказы зоны × тариф на дату доставки) + бонусы − удержания. Бонусов и удержаний в системе нет.
* **Тариф** = цена доставки зоны, зафиксированная в заказе (снимок `delivery_price_snapshot.base_price`, есть с 23.09.2026); до этой даты берётся текущая цена зоны. Строки бара разделены по версии тарифа: «Зона · цена».
* Отменённые заказы не оплачиваются.

Источник: живая база курьерки, `orders`, `shop_zones`.""",
)


# ===========================================================================
# Сборщики
# ===========================================================================
P = "ops_picker_orders"
PS = "ops_picker_stats"

_FINISHED_ORDERS = "COUNT(DISTINCT CASE WHEN is_finished = 1 THEN order_id END)"

report(
    "R8", "Сборщики", "Производительность", "P0",
    "ops_picker_orders, ops_picker_stats (витрина ТЗ dm_picker_perf_daily)", COPY_REFRESH,
    kpis=[
        kpi("r8_k1", "Собрано заказов", P, _FINISHED_ORDERS, INT, "Только завершённые задачи"),
        kpi("r8_k2", "Собрано единиц", P,
            "SUM(CASE WHEN is_finished = 1 THEN positions_assembled ELSE 0 END)", INT,
            "Позиции без отклонённых, шт."),
        kpi("r8_k3", "Чистое время", P, "AVG(assembly_minutes)", NUM1,
            "Начало → передача курьеру, мин"),
        kpi("r8_k4", "Средний чек", P,
            "SUM(order_check * order_weight) / NULLIF(SUM(CASE WHEN order_check IS NOT NULL THEN order_weight END), 0)",
            RUB, "Собранные заказы, ₽"),
    ],
    rows=[[
        (hbar("r8_p3", "Время сборки", "Среднее чистое время по сотрудникам", P,
              "picker_name", "AVG(assembly_minutes)", "Минут", NUM1, limit=50,
              cat_title="Сборщик", val_title="Минут"), 6),
        (hbar("r8_p4", "Производительность", "Собранные заказы", P,
              "picker_name", _FINISHED_ORDERS, "Заказов", INT, limit=50,
              cat_title="Сборщик", val_title="Заказов"), 6),
    ]],
    table=table_raw(
        "r8_p5", "Детализация показателей", "", PS,
        [("picker_name", None), ("orders", INT), ("units", INT), ("net_minutes", NUM1),
         ("full_minutes", NUM1), ("median_minutes", NUM1), ("p90_minutes", NUM1),
         ("orders_per_hour", NUM1), ("units_per_order", NUM1), ("avg_check", RUB),
         ("replaced_pct", NUM1)],
        order_by="orders"),
    note="Отдельного момента «назначен сборщику» система не хранит, поэтому полное время недоступно. "
         "Сборки дольше суток в среднее не входят.",
    method="""* **Чистое время** = передача курьеру − начало сборки (первая сборка сборщика по заказу → последняя). Слотовые заказы потом ждут курьера, это время сюда не входит.
* **Заказов в час** = собранные заказы / часы смен сборщика (`shifts`). Параллельная сборка искажает среднее, поэтому смотрите медиану и p90.
* **Средний чек** считается по уникальным доставленным заказам (если заказ собирали двое, он делится между ними).
* **% с заменой**: заказы сборщика, где была хотя бы одна замена.

Источник: `assemblies`, `order_items` подзаказов сборки, `orders`.""",
)

report(
    "R9", "Сборщики", "Качество сборки", "P1",
    "ops_picker_orders (витрина ТЗ dm_picker_quality)", COPY_REFRESH,
    kpis=[
        kpi("r9_k1", "Жалобы на сборку", P,
            "COUNT(DISTINCT CASE WHEN has_error = 1 THEN order_id END)", INT,
            "Ответственная роль — сборщик"),
        kpi("r9_k2", "Заказы с жалобой", P,
            f"COUNT(DISTINCT CASE WHEN has_error = 1 THEN order_id END) / NULLIF({_FINISHED_ORDERS}, 0)",
            PCT, "% собранных заказов"),
        kpi("r9_k3", "Компенсации", P, "CAST(NULL AS DECIMAL(12, 2))", RUB,
            "По атрибутированным: в системе нет"),
        kpi("r9_k4", "Жалобы на курьеров", P, "CAST(NULL AS SIGNED)", INT,
            "Не входят в ошибки сборки"),
    ],
    rows=[[
        (pivot("r9_p3", "Ошибки по сотрудникам", "Сборщик × тип жалобы", P,
               "picker_name", "error_type_name", "COUNT(DISTINCT order_id)", "Жалоб", INT,
               filters=["has_error = 1"]), 6),
        (hbar("r9_p4", "Типы ошибок", "Жалобы с ответственностью сборщика", P,
              "error_type_name", "COUNT(DISTINCT order_id)", "Жалоб", INT,
              filters=["has_error = 1"], cat_title="Тип ошибки", val_title="Жалоб"), 6),
    ]],
    table=table(
        "r9_p5", "Детализация показателей", "", P, ["picker_name"],
        [("COUNT(DISTINCT CASE WHEN has_error = 1 THEN order_id END)", "Жалоб", INT),
         ("COUNT(DISTINCT CASE WHEN has_error = 1 THEN order_id END)", "Заказов с жалобой", INT),
         (f"COUNT(DISTINCT CASE WHEN has_error = 1 THEN order_id END) / NULLIF({_FINISHED_ORDERS}, 0)",
          "Процент ошибок", PCT),
         ("SUM(positions_no_offer)", "Несогласованные замены", INT),
         ("AVG(review_rating)", "Оценка", NUM2),
         (f"MAX({NO_DATA})", "Компенсации, ₽", None)],
        sort_label="Жалоб"),
    note="Тип ошибки проставляет оператор в карточке отзыва на заказ (админка → отзывы). "
         "Пока типы не проставлены, жалоб нет. Компенсации и жалобы на курьеров система не хранит.",
    method="""* **Жалоба** = отзыв на заказ с типом ошибки: недовоз, пересорт, просрочка или другое. Относится на сборщиков заказа.
* **Процент ошибок** = заказы с жалобой / собранные заказы. Недовоз и просрочка могут быть зоной курьера, тип выбирает оператор.
* **Несогласованная замена**: замена в заказе, где клиент не просил позвонить при отсутствии товара.

Источник: `order_reviews.error_type`, `assemblies`, `order_items`.""",
)


# ===========================================================================
# Клиенты
# ===========================================================================
CO = "ops_client_orders"
CP = "ops_client_periods"
RFM = "ops_client_rfm"
SL = "ops_client_segment_slices"
COH = "ops_client_cohorts"
REV = "ops_client_reviews"
REVP = "ops_client_review_periods"
GP = "ops_order_geo_points"
GF = "ops_order_geo_flows"

_SEG = "Сегменты: потерянные (90+ дней без покупки), риск (31–89), новые (одна покупка за 365 дней), " \
       "лучшие (R≥4 · F≥4 · M≥4), лояльные (3+ заказа), развивающиеся (остальные). Проверяются по порядку, клиент попадает в один сегмент."
_RFM_SCALE = "**R** по давности: 0–7 / 8–14 / 15–30 / 31–60 / 61+ дней → R5…R1. **F** по заказам за 365 дней: " \
             "1 / 2 / 3–4 / 5–9 / 10+ → F1…F5. **M** по сумме за 365 дней: до 1 тыс. / 1–3 / 3–7 / 7–15 / от 15 тыс. ₽ → M1…M5."
_ASOF = "Дата среза = конец выбранного периода; RFM на дату считается из истории заказов (история с 19.05.2026, ежедневных снимков нет)."

report(
    "R10", "Клиенты", "База и рост", "P0",
    "ops_client_orders, ops_client_periods (витрина ТЗ dm_customer_base_daily)", COPY_REFRESH,
    kpis=[
        kpi("r10_k1", "Покупающие клиенты", CO, "COUNT(DISTINCT client_id)", INT, "Уникальные за период"),
        kpi("r10_k2", "Новые клиенты", CO, "COUNT(DISTINCT CASE WHEN is_first = 1 THEN client_id END)", INT,
            "Первая покупка в периоде"),
        kpi("r10_k3", "Повторные клиенты", CO,
            "COUNT(DISTINCT CASE WHEN is_repeat_client = 1 THEN client_id END)", INT, "Покупали до начала"),
        kpi("r10_k4", "Вернувшиеся", CO, "COUNT(DISTINCT CASE WHEN is_returned = 1 THEN client_id END)", INT,
            "После перерыва ≥ 90 дней"),
        kpi("r10_k5", "Выручка на клиента", CO, "SUM(total) / NULLIF(COUNT(DISTINCT client_id), 0)", RUB,
            "Net за период / покупатели"),
        kpi("r10_k6", "Частота покупок", CO, "COUNT(*) / NULLIF(COUNT(DISTINCT client_id), 0)", NUM2,
            "Заказов на покупателя"),
    ],
    rows=[[
        (line("r10_p3", "Динамика клиентской базы", "Уникальные покупатели и активные на конец интервала", CP,
              "period_label",
              [("SUM(buyers)", "Покупатели в интервале"), ("SUM(active_end)", "Активные на конец (≤ 30 дней)")],
              INT, cat_title="Интервал", val_title="Клиентов"), 6),
        (vbar("r10_p4", "Приток, возврат и отток", "Уникальные клиенты в каждом интервале", CP,
              "period_label",
              [("SUM(new_clients)", "Новые"), ("SUM(returned_clients)", "Вернувшиеся"),
               ("SUM(lost_clients)", "Перешли в потерянные")],
              INT, cat_title="Интервал", val_title="Клиентов"), 6),
    ]],
    table=table_raw(
        "r10_p5", "Динамика по периодам", "", CP,
        [("period_label", None), ("buyers", INT), ("new_clients", INT), ("repeat_clients", INT),
         ("returned_clients", INT), ("lost_clients", INT), ("active_end", INT), ("orders", INT),
         ("revenue", RUB)],
        order_by="period_start", desc=False),
    note="Интервал выбирается фильтром «Группировка» (месяц или неделя, неделя с понедельника). "
         "Покупки клиентов идут с 19.05.2026, поэтому «вернувшихся» после 90 дней пока почти нет.",
    method="""* **Покупатель** = клиент витрины с доставленным заказом. Контакты в отчёт не выводятся, только ID.
* **Новый** = первая покупка в периоде; **повторный** = покупал до начала периода; **вернувшийся** = покупка после перерыва 90 дней и больше.
* **Перешли в потерянные** = у клиента в интервале исполнилось 90 дней без покупки. **Активные на конец** = покупали за 30 дней до конца интервала.
* **Выручка** = итог доставленных заказов витрины.

Источник: заказы витрины (`orders`, status = delivered), бизнес-дата = дата оформления.""",
)

report(
    "R11", "Клиенты", "Удержание и отток", "P1",
    "ops_client_rfm, ops_client_cohorts (витрина ТЗ dm_customer_cohort)", COPY_REFRESH,
    kpis=[
        kpi("r11_k1", "Клиенты в зоне риска", RFM, "SUM(is_risk) / NULLIF(COUNT(*), 0)", PCT,
            "% выбранной базы, 31–89 дней без покупки"),
        kpi("r11_k2", "Потерянные клиенты", RFM, "SUM(is_lost)", INT, "90 и больше дней без покупки"),
        kpi("r11_k3", "Выручка клиентов в риске", RFM,
            "SUM(CASE WHEN is_risk = 1 THEN monetary_365 ELSE 0 END)", RUB, "Историческая net за 365 дней"),
        kpi("r11_k4", "Вернувшиеся за период", CO,
            "COUNT(DISTINCT CASE WHEN is_returned = 1 THEN client_id END)", INT,
            "Уникальные после перерыва ≥ 90 дней"),
    ],
    rows=[[
        (hbar("r11_p3", "Давность последней покупки", "Клиентов на дату среза", RFM,
              "recency_bucket", "COUNT(*)", "Клиентов", INT, sort="label_desc",
              cat_title="Дней без покупки", val_title="Клиентов"), 6),
        (pivot("r11_p4", "Когортное удержание", "Месяц первой покупки × месяц жизни", COH,
               "cohort_month", "life_month",
               "SUM(active_clients) / NULLIF(SUM(cohort_size), 0)", "Удержание", PCT,
               totals=False), 6),
    ]],
    table=table(
        "r11_p5", "Аудитории для возврата", "", RFM, ["segment"],
        [("COUNT(*)", "Клиентов", INT),
         ("COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (), 0)", "Доля базы", PCT),
         ("SUM(monetary_365)", "Выручка за 365 дней, ₽", RUB),
         ("AVG(recency_days)", "Средняя давность, дней", NUM1),
         ("MAX(segment_action)", "Рекомендуемое действие", None)],
        sort_label="Клиентов"),
    note="Когортная матрица всегда за всю историю, фильтр «Период» на неё не действует. "
         "Незавершённые месяцы жизни когорты (текущий и будущие) не показываются: ячейка пустая, а не ноль.",
    method=f"""* **Риск** = 31–89 дней без покупки, **потерян** = 90 и больше. {_ASOF}
* **Удержание когорты** = клиенты когорты с покупкой в месяце жизни M / размер когорты. Когорта = месяц первой покупки, M0 = месяц первой покупки.
* **Аудитории** = сегменты RFM на дату среза. {_SEG}

Источник: заказы витрины, status = delivered.""",
)

report(
    "R12", "Клиенты", "RFM-анализ", "P1",
    "ops_client_rfm (витрина ТЗ dm_customer_rfm)", COPY_REFRESH,
    kpis=[
        kpi("r12_k1", "Клиенты в RFM", RFM, "COUNT(*)", INT, "С покупкой не позже даты среза"),
        kpi("r12_k2", "Лучшие клиенты", RFM, "SUM(CASE WHEN segment = 'Лучшие' THEN 1 ELSE 0 END)", INT,
            "R≥4 · F≥4 · M≥4"),
        kpi("r12_k3", "Лояльные клиенты", RFM, "SUM(CASE WHEN segment = 'Лояльные' THEN 1 ELSE 0 END)", INT,
            "Регулярные активные"),
        kpi("r12_k4", "Выручка базы", RFM, "SUM(monetary_365)", RUB, "Net за 365 дней"),
    ],
    rows=[[
        (donut("r12_p3", "Структура клиентской базы", "Взаимоисключающие RFM-сегменты", RFM,
               "segment", "COUNT(*)", "Клиентов", INT), 6),
        (pivot("r12_p4", "Матрица R × F", "Давность и частота покупок", RFM,
               "r_score", "f_score", "COUNT(*)", "Клиентов", INT, row_order="key_z_to_a"), 6),
    ]],
    table=table_raw(
        "r12_p5", "Клиенты выбранного RFM-сегмента", "", RFM,
        [("client_id", None), ("segment", None), ("last_order_at", None), ("recency_days", INT),
         ("frequency_365", INT), ("monetary_365", RUB), ("rfm_scores", None), ("rfm_code", None),
         ("segment_reason", None), ("segment_action", None)],
        order_by="monetary_365"),
    note="Сегмент над таблицей выбирается фильтром «Сегмент».",
    method=f"""{_RFM_SCALE}

{_SEG} {_ASOF}

Источник: заказы витрины, status = delivered.""",
)

report(
    "R13", "Клиенты", "Работа с клиентами", "P1",
    "ops_client_rfm (витрина ТЗ dm_customer_rfm)", COPY_REFRESH,
    kpis=[
        kpi("r13_k1", "Клиентов в выборке", RFM, "COUNT(*)", INT, "Все строки доступны в CSV"),
        kpi("r13_k2", "В зоне риска", RFM, "SUM(is_risk)", INT, "31–89 дней без покупки"),
        kpi("r13_k3", "Потерянные", RFM, "SUM(is_lost)", INT, "От 90 дней без покупки"),
        kpi("r13_k4", "Выручка выборки", RFM, "SUM(monetary_365)", RUB, "Историческая net за 365 дней"),
    ],
    rows=[[
        (hbar("r13_p3", "Историческая выручка по сегментам", "Покупки за 365 дней · не прогноз потерь", RFM,
              "segment", "SUM(monetary_365)", "Выручка, ₽", RUB, cat_title="Сегмент", val_title="Выручка, ₽"), 6),
        (hbar("r13_p4", "Клиенты по сегментам", "Размер аудиторий для адресных акций", RFM,
              "segment", "COUNT(*)", "Клиентов", INT, cat_title="Сегмент", val_title="Клиентов"), 6),
    ]],
    table=table_raw(
        "r13_p5", "Список клиентов для адресных акций", "", RFM,
        [("client_id", None), ("segment", None), ("first_order_at", None), ("last_order_at", None),
         ("recency_days", INT), ("frequency_365", INT), ("monetary_365", RUB), ("avg_check_365", RUB),
         ("r_score", None), ("f_score", None), ("m_score", None), ("rfm_code", None),
         ("shop_name", None), ("segment_reason", None), ("segment_action", None)],
        order_by="monetary_365"),
    note="Выгрузка CSV отдаёт всю выборку по фильтрам. Согласия на рассылки в системе хранятся отдельно и здесь не учтены.",
    method=f"""* **Аудитория** = клиенты, отобранные фильтрами на дату среза (сегмент, когорта, R/F/M, магазин).
* **Выручка** = net за 365 дней до даты среза: это история покупок, а не прогноз потерь.

{_SEG}""",
)

report(
    "R14", "Клиенты", "Переходы RFM", "P1",
    "ops_client_rfm, ops_client_segment_slices (витрина ТЗ dm_rfm_transition)", COPY_REFRESH,
    kpis=[
        kpi("r14_k1", "Клиентов в выборке", RFM, "COUNT(*)", INT, "На втором срезе есть покупка"),
        kpi("r14_k2", "Сохранили сегмент", RFM,
            "SUM(CASE WHEN transition_kind = 'Сохранили сегмент' THEN 1 ELSE 0 END)", INT,
            "Одинаковый сегмент на двух датах"),
        kpi("r14_k3", "Перешли в риск", RFM,
            "SUM(CASE WHEN transition_kind = 'Перешли в риск' THEN 1 ELSE 0 END)", INT, "Из другого сегмента"),
        kpi("r14_k4", "Стали потерянными", RFM,
            "SUM(CASE WHEN transition_kind = 'Стали потерянными' THEN 1 ELSE 0 END)", INT, "Были в базе"),
        kpi("r14_k5", "Вернулись в активные", RFM,
            "SUM(CASE WHEN transition_kind = 'Вернулись в активные' THEN 1 ELSE 0 END)", INT,
            "Из риска или потерянных"),
        kpi("r14_k6", "Впервые купили", RFM,
            "SUM(CASE WHEN transition_kind = 'Впервые купили' THEN 1 ELSE 0 END)", INT, "После первого среза"),
    ],
    rows=[
        [(pivot("r14_p3", "Матрица переходов", "Исходный сегмент × конечный сегмент", RFM,
                "a_segment", "segment", "COUNT(*)", "Клиентов", INT), 12)],
        [
            (hbar("r14_p4", "Самые массовые переходы", "Топ-8 изменений сегмента · без сохранённых", RFM,
                  "transition_pair", "COUNT(*)", "Клиентов", INT, limit=8,
                  filters=["a_segment <> segment"], cat_title="Было → стало", val_title="Клиентов"), 6),
            (hbar("r14_p5", "Изменение численности сегментов", "Два среза одной выбранной аудитории", SL,
                  "slice_segment", "COUNT(*)", "Клиентов", INT, groupby=["slice_name"], sort="label_asc",
                  cat_title="Сегмент", val_title="Клиентов"), 6),
        ],
    ],
    table=table_raw(
        "r14_p6", "Клиенты и изменение RFM", "", RFM,
        [("client_id", None), ("a_segment", None), ("segment", None), ("transition_kind", None),
         ("a_rfm_scores", None), ("rfm_scores", None), ("a_monetary_365", RUB), ("monetary_365", RUB),
         ("last_order_at", None), ("shop_name", None), ("segment_action", None)],
        order_by="monetary_365"),
    note="Первый срез = начало выбранного периода, второй = конец. Фильтр «Сегмент» отбирает конечный сегмент и знаменатель не меняет.",
    method=f"""* **Переход** = сегмент на дату A (начало периода) → сегмент на дату B (конец периода). Доля считается от исходного сегмента.
* «Не было в базе» = на дату A покупок ещё не было (впервые купили между срезами).
* В ячейке матрицы число клиентов; доля от исходного сегмента видна по итогу строки.

{_SEG} {_ASOF}""",
)

report(
    "R15", "Клиенты", "Отзывы клиентов", "P1",
    "ops_client_reviews, ops_client_review_periods (витрина ТЗ dm_review_daily)", COPY_REFRESH,
    kpis=[
        kpi("r15_k1", "Средняя оценка", REV, "AVG(rating)", NUM2, "Шкала 1–5, без неоценённых"),
        kpi("r15_k2", "Отзывов", REV, "COUNT(*)", INT, "Отзывы на заказы"),
        kpi("r15_k3", "Оценок 1–3", REV,
            "SUM(CASE WHEN rating <= 3 THEN 1 ELSE 0 END) / NULLIF(COUNT(rating), 0)", PCT, "% от оценённых"),
        kpi("r15_k4", "Недовольных клиентов", REV,
            "COUNT(DISTINCT CASE WHEN rating <= 3 THEN client_id END)", INT, "Уникальные авторы 1–3"),
        kpi("r15_k5", "Без текста", REV, "SUM(is_without_text)", INT, "Причина неизвестна"),
        kpi("r15_k6", "Без оценки", REV, "SUM(CASE WHEN rating IS NULL THEN 1 ELSE 0 END)", INT,
            "Исключены из среднего"),
    ],
    rows=[[
        (hbar("r15_p3", "Распределение оценок", "Количество отзывов · без неоценённых", REV,
              "rating_label", "COUNT(*)", "Отзывов", INT, filters=["rating IS NOT NULL"],
              sort="label_asc", cat_title="Оценка", val_title="Отзывов"), 4),
        (hbar("r15_p4", "Почему недовольны", "Причины отзывов с оценками 1–3", REV,
              "reason_name", "COUNT(*)", "Отзывов", INT, filters=["rating <= 3"],
              cat_title="Причина", val_title="Отзывов"), 4),
        (line("r15_p5", "Динамика средней оценки", "По выбранной аудитории и фильтрам", REVP,
              "period_label", [("AVG(rating)", "Средняя оценка")], NUM2,
              cat_title="Интервал (* неполный)", val_title="Оценка", y_bounds=(1, 5)), 4),
    ]],
    table=table_raw(
        "r15_p6", "Отзывы и конкретные клиенты", "", REV,
        [("review_id", None), ("client_id", None), ("order_id", None), ("reviewed_at", None),
         ("rating", None), ("review_text", None), ("reason_name", None), ("shop_name", None),
         ("segment", None), ("rfm_code", None), ("next_step", None)],
        order_by="reviewed_at"),
    note="Причина отзыва = тип ошибки, который оператор проставляет в карточке отзыва; у остальных «Причина не указана». "
         "Выгрузка CSV таблицы отдаёт клиентов для CRM.",
    method=f"""* **Средняя оценка** только по оценкам 1–5; негатив строго 1–3; отзыв без оценки не входит в среднее и долю. Одна строка = один отзыв на заказ.
* **Динамика:** интервал по фильтру «Группировка», незавершённый интервал помечен «*», интервалы без оценок дают разрыв линии.
* **Сегмент и RFM** клиента на конец выбранного периода. {_SEG}

Источник: отзывы на заказы (`order_reviews`), заказы витрины.""",
)

report(
    "R16", "Клиенты", "География заказов", "P1",
    "ops_order_geo_points, ops_order_geo_flows (витрины ТЗ dm_order_geo_od, dm_order_geo_flow)", COPY_REFRESH,
    kpis=[
        kpi("r16_k1", "Заказы на карте", GP,
            "COUNT(DISTINCT CASE WHEN has_coords = 1 AND is_delivered = 1 THEN order_id END)", INT,
            "С координатами", filters=["point_type = 'Доставка'"]),
        kpi("r16_k2", "Покрытие геоданными", GP,
            "SUM(has_coords * is_delivered) / NULLIF(SUM(is_delivered), 0)", PCT,
            "% доставленных", filters=["point_type = 'Доставка'"]),
        kpi("r16_k3", "Средняя дальность", GP, "AVG(CASE WHEN is_delivered = 1 THEN distance_km END)", NUM1,
            "Магазин → адрес, км", filters=["point_type = 'Доставка'"]),
        kpi("r16_k4", "Среднее время доставки", GP,
            "AVG(CASE WHEN is_delivered = 1 THEN delivery_minutes END)", NUM1,
            "Принял → выдал, мин", filters=["point_type = 'Доставка'"]),
    ],
    rows=[
        [(scatter("r16_p3", "Карта заказов", "Точки доставки и магазины · размер — число заказов", GP,
                  filters=["is_delivered = 1", "has_coords = 1"]), 12)],
        [
            (arc("r16_p4", "Откуда и куда", "Потоки магазин → район · толщина — заказы", GF), 6),
            (hbar("r16_p5", "Заказы по районам", "Топ-15 зон назначения", GP,
                  "district", "COUNT(DISTINCT order_id)", "Заказов", INT, limit=15,
                  filters=["point_type = 'Доставка'", "is_delivered = 1"],
                  cat_title="Район / зона", val_title="Заказов"), 6),
        ],
    ],
    table=table(
        "r16_p6", "Магазин → район", "", GP, ["shop_name", "district"],
        [("COUNT(DISTINCT CASE WHEN is_delivered = 1 THEN order_id END)", "Заказов", INT),
         ("SUM(is_delivered) / NULLIF(SUM(SUM(is_delivered)) OVER (), 0)", "Доля", PCT),
         ("AVG(CASE WHEN is_delivered = 1 THEN distance_km END)", "Средняя дальность, км", NUM1),
         ("AVG(CASE WHEN is_delivered = 1 THEN delivery_minutes END)", "Среднее время, мин", NUM1),
         ("AVG(CASE WHEN is_delivered = 1 THEN total END)", "Средний чек, ₽", RUB),
         ("SUM(is_cancelled) / NULLIF(COUNT(*), 0)", "% отмен", PCT)],
        sort_label="Заказов", filters=["point_type = 'Доставка'"]),
    note="Адреса округлены до 3 знаков координат (около 100 м): точные адреса и квартиры на карте не видны. "
         "Толщина дуг одинаковая, число заказов в подсказке и в таблице. Вид карты зафиксирован на Нальчике.",
    method="""* **Покрытие** = доставленные заказы с координатами / доставленные заказы.
* **Точка доставки** = адрес заказа, округлённый до ~100 м; **район** = зона доставки на момент заказа.
* **Поток** = магазин → район, цель дуги = центр доставок района; показываются 200 самых крупных потоков.
* **Дальность** = расстояние по прямой от магазина до адреса.

Подложка карты: OpenStreetMap без ключа. Источник: адреса заказов витрины (`order_addresses`), зоны и время доставки курьерки.""",
)


# ===========================================================================
# Качество данных
# ===========================================================================
DQ = "ops_dq_checks"

DQ_TAB = {
    "tab": "Качество данных",
    "title": "Качество данных",
    "refresh": COPY_REFRESH,
    "kpis": [
        kpi("dq_k1", "Проверки", DQ, "COUNT(*)", INT, "Правила раздела 6.6 ТЗ"),
        kpi("dq_k2", "Без ошибок", DQ, "SUM(CASE WHEN status = 'Без ошибок' THEN 1 ELSE 0 END)", INT,
            "Проверки в норме"),
        kpi("dq_k3", "Нужна проверка", DQ, "SUM(CASE WHEN status = 'Нужна проверка' THEN 1 ELSE 0 END)", INT,
            "Нарушения или списки на разбор"),
        kpi("dq_k4", "Актуальность витрин", DQ, "MAX(copy_age_hours)", NUM1,
            "Часов с обновления копии БД"),
    ],
    "table": table_raw(
        "dq_p5", "Проверки качества данных", "", DQ,
        [("check_name", None), ("check_what", None), ("result_label", None), ("expected", None),
         ("status", None)],
        order_by="check_order", desc=False),
    "note": "Проверки считаются по выбранному периоду и магазину. Копия БД обновляется ночью; вкладка «Курьеры» читает живую базу.",
}


# ---------------------------------------------------------------------------
# Вкладки дашборда
# ---------------------------------------------------------------------------

TABS = [
    ("Товары", "📦"),
    ("Курьеры", "🛵"),
    ("Сборщики", "🧺"),
    ("Клиенты", "👥"),
    ("Качество данных", "✅"),
]


def all_charts() -> list[dict]:
    out = []
    for r in REPORTS:
        out += r["kpis"]
        for row in r["rows"]:
            out += [c for c, _ in row]
        if r["table"]:
            out.append(r["table"])
        for _, t in r["table_tabs"] or []:
            out.append(t)
    out += DQ_TAB["kpis"] + [DQ_TAB["table"]]
    return out


# ---------------------------------------------------------------------------
# Фильтры (раздел 3.4 ТЗ): native filters, область = вкладки или отчёты.
# scope: список названий вкладок и кодов отчётов; None = весь дашборд.
# ---------------------------------------------------------------------------

OPS_FILTERS = [
    {"type": "time", "name": "Период",
     "default": 'DATEADD(DATETIME("today"), -30, day) : DATEADD(DATETIME("today"), 1, day)',
     "description": "По умолчанию последние 30 дней включая сегодня. Для клиентских отчётов "
                    "начало периода = первый срез, конец = дата среза."},
    {"type": "select", "name": "Магазин", "dataset": "ops_picker_orders", "column": "shop_name",
     "multi": True},
    {"type": "select", "name": "Категория", "dataset": "ops_assembly_items", "column": "category_name",
     "multi": True, "scope": ["Товары"]},
    {"type": "select", "name": "Товары", "dataset": "ops_assembly_items", "column": "product_name",
     "multi": True, "search_all": True, "scope": ["Товары"]},
    {"type": "select", "name": "Причина замены", "dataset": "ops_assembly_items",
     "column": "replace_reason_name", "multi": True, "scope": ["R2"]},
    {"type": "select", "name": "Сборщик", "dataset": "ops_picker_orders", "column": "picker_name",
     "multi": True, "scope": ["R2", "Сборщики"]},
    {"type": "select", "name": "Курьер", "dataset": "ops_courier_orders", "column": "courier_name",
     "multi": True, "scope": ["Курьеры"]},
    {"type": "select", "name": "Зона", "dataset": "ops_courier_orders", "column": "zone_name",
     "multi": True, "scope": ["R4", "R6", "R7"]},
    {"type": "select", "name": "Способ оплаты", "dataset": "ops_courier_orders",
     "column": "payment_method", "multi": True, "scope": ["R5"]},
    {"type": "select", "name": "Группировка", "dataset": "ops_client_periods", "column": "grain",
     "multi": False, "required": True, "default": "Месяц", "scope": ["Клиенты"],
     "description": "Интервал для динамики: месяц или неделя (с понедельника)."},
    {"type": "select", "name": "Сегмент", "dataset": "ops_client_rfm", "column": "segment",
     "multi": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "Сегмент на первом срезе", "dataset": "ops_client_rfm",
     "column": "a_segment", "multi": True, "scope": ["R14"]},
    {"type": "select", "name": "Когорта", "dataset": "ops_client_rfm", "column": "cohort_month",
     "multi": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "R", "dataset": "ops_client_rfm", "column": "r_score",
     "multi": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "F", "dataset": "ops_client_rfm", "column": "f_score",
     "multi": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "M", "dataset": "ops_client_rfm", "column": "m_score",
     "multi": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "ID клиента", "dataset": "ops_client_rfm", "column": "client_id",
     "multi": True, "search_all": True, "scope": ["Клиенты"]},
    {"type": "select", "name": "Оценка", "dataset": "ops_client_reviews", "column": "rating_label",
     "multi": True, "scope": ["R15"]},
    {"type": "select", "name": "Причина отзыва", "dataset": "ops_client_reviews",
     "column": "reason_name", "multi": True, "scope": ["R15"]},
]


# ---------------------------------------------------------------------------
# Генерация
# ---------------------------------------------------------------------------

def _chart_doc(c: dict) -> dict:
    return {
        "slice_name": c["title"],
        "description": c["subtitle"] or None,
        "viz_type": c["params"]["viz_type"],
        "params": c["params"],
        "cache_timeout": None,
        "dataset_uuid": DS_UUID[c["dataset"]],
        "uuid": c["uuid"],
        "version": "1.0.0",
    }


def emit_charts() -> list[pathlib.Path]:
    """Пишет Ops_*.yaml; прежние Ops_*.yaml удаляет (их uuid - в legacy-списке)."""
    for old in CHARTS_DIR.glob("Ops_*.yaml"):
        old.unlink()
    written = []
    for c in all_charts():
        path = CHARTS_DIR / f"Ops_{c['key']}.yaml"
        header = (
            "# Сгенерировано scripts/ops_reports.py («Операционные отчёты», ТЗ v2.1).\n"
            "# Не править руками.\n"
        )
        path.write_text(
            header + yaml.safe_dump(_chart_doc(c), allow_unicode=True, sort_keys=False, width=1000),
            encoding="utf-8",
        )
        written.append(path)
    return written


def _md_header(r: dict) -> str:
    return (
        f"### {r['code']}. {r['title']}\n"
        f"Вкладка «{r['tab']}» · {r['priority']} · обновление: {r['refresh']} · "
        f"датасет: {r['source']}"
    )


def _md_note(text: str) -> str:
    return f"**Примечание.** {text}"


def _md_method(text: str) -> str:
    return f"<details><summary><b>Методика</b></summary>\n\n{text}\n\n</details>"


def dashboard_manifest(dashboard_uuid: str) -> dict:
    """Манифест для build_dashboards.py (layout = "reports")."""
    tabs = []
    for name, icon in TABS:
        if name == "Качество данных":
            tabs.append({
                "title": f"{icon} {name}",
                "name": name,
                "dq": {
                    "header": f"### {DQ_TAB['title']}\nСлужебная вкладка: проверки данных · "
                              f"обновление: {DQ_TAB['refresh']}",
                    "kpis": [c["uuid"] for c in DQ_TAB["kpis"]],
                    "table": DQ_TAB["table"]["uuid"],
                    "note": _md_note(DQ_TAB["note"]),
                },
            })
            continue
        reports = [r for r in REPORTS if r["tab"] == name]
        tabs.append({
            "title": f"{icon} {name} · {len(reports)}",
            "name": name,
            "reports": [
                {
                    "code": r["code"],
                    "title": f"{r['code']} · {r['title']}",
                    "header": _md_header(r),
                    "kpis": [c["uuid"] for c in r["kpis"]],
                    "rows": [[(c["uuid"], w, c["kind"]) for c, w in row] for row in r["rows"]],
                    "table": r["table"]["uuid"] if r["table"] else None,
                    "table_tabs": [(t, c["uuid"]) for t, c in (r["table_tabs"] or [])],
                    "note": _md_note(r["note"]),
                    "method": _md_method(r["method"]),
                }
                for r in reports
            ],
        })
    return {
        "dashboard_title": "Операционные отчёты",
        "uuid": dashboard_uuid,
        "slug": "ops-reports",
        "layout": "reports",
        "color_scheme": COLOR_SCHEME,
        "css": DASHBOARD_CSS,
        "report_tabs": tabs,
        "filters": OPS_FILTERS,
        "expanded": [c["uuid"] for c in all_charts() if c["subtitle"]],
    }


def main() -> None:
    written = emit_charts()
    print(f"чартов записано: {len(written)}")
    for r in REPORTS:
        n = len(r["kpis"]) + sum(len(row) for row in r["rows"]) + (1 if r["table"] else 0) \
            + len(r["table_tabs"] or [])
        print(f"  {r['code']:>4} {r['title']}: {n}")


if __name__ == "__main__":
    main()
