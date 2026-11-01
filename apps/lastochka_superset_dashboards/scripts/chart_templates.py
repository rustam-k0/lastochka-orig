#!/usr/bin/env python
"""Генераторы params для типовых чартов Superset 6.1.0.

Назначение: справочник копирования для задач вкладок (2-8). НЕ рантайм - функции
возвращают готовый dict `params`, который кладётся в поле `params` chart-YAML.
Все viz_type - реальные из Superset 6.1.0.

Формат метрики (аргумент `metric`/элемент `metrics`) - либо строка (имя сохранённой
метрики датасета), либо adhoc-словарь. Хелперы:
    sql_metric("SUM(amount)", "Выручка")          → adhoc SQL-метрика
    simple_metric("amount", "SUM", "Выручка")     → adhoc SIMPLE-метрика

Хелпер сборки полного chart-YAML:
    build_chart(slice_name, viz_params, dataset_uuid, uuid) → dict
    dump_chart_yaml(...)                                     → str (YAML)

Соответствие «функция → viz_type»:
    big_number                → big_number_total        (одно число + сабхедер)
    big_number_with_trendline → big_number              (число + спарклайн по времени)
    timeseries_line           → echarts_timeseries_line
    bar                       → echarts_timeseries_bar
    pie                       → pie
    table                     → table
    gauge                     → gauge_chart
    funnel                    → funnel
    heatmap                   → heatmap_v2
"""
from __future__ import annotations

# ---------------------------------------------------------------------------
# Хелперы метрик
# ---------------------------------------------------------------------------

def sql_metric(sql_expression: str, label: str) -> dict:
    """Adhoc-метрика на произвольном SQL-выражении."""
    return {
        "expressionType": "SQL",
        "sqlExpression": sql_expression,
        "label": label,
        "hasCustomLabel": True,
    }


def simple_metric(column: str, aggregate: str, label: str) -> dict:
    """Adhoc-метрика вида AGG(column) (aggregate: SUM/COUNT/AVG/MIN/MAX/COUNT_DISTINCT)."""
    return {
        "expressionType": "SIMPLE",
        "column": {"column_name": column},
        "aggregate": aggregate,
        "label": label,
        "hasCustomLabel": True,
    }


def _as_list(x):
    if x is None:
        return []
    return list(x) if isinstance(x, (list, tuple)) else [x]


# ---------------------------------------------------------------------------
# 1. big_number_total - одно число + необязательный сабхедер
# ---------------------------------------------------------------------------

def big_number(metric, subheader: str = "") -> dict:
    return {
        "viz_type": "big_number_total",
        "metric": metric,
        "adhoc_filters": [],
        "subheader": subheader,
        "header_font_size": 0.4,
        "subheader_font_size": 0.15,
        "y_axis_format": "SMART_NUMBER",
    }


# ---------------------------------------------------------------------------
# 2. big_number - число + трендовый спарклайн по времени
# ---------------------------------------------------------------------------

def big_number_with_trendline(metric, dttm: str, time_grain: str = "P1D") -> dict:
    return {
        "viz_type": "big_number",
        "metric": metric,
        "granularity_sqla": dttm,
        "time_grain_sqla": time_grain,
        "adhoc_filters": [],
        "compare_lag": "",
        "compare_suffix": "",
        "show_trend_line": True,
        "start_y_axis_at_zero": True,
        "y_axis_format": "SMART_NUMBER",
    }


# ---------------------------------------------------------------------------
# 3. echarts_timeseries_line - линейный график по времени
# ---------------------------------------------------------------------------

def timeseries_line(metrics, dttm: str, groupby=None, time_grain: str = "P1D") -> dict:
    return {
        "viz_type": "echarts_timeseries_line",
        "metrics": _as_list(metrics),
        "groupby": _as_list(groupby),
        "x_axis": dttm,
        "time_grain_sqla": time_grain,
        "adhoc_filters": [],
        "row_limit": 10000,
        "comparison_type": "values",
        "x_axis_sort_asc": True,
        "y_axis_format": "SMART_NUMBER",
    }


# ---------------------------------------------------------------------------
# 4. echarts_timeseries_bar - столбчатый график
# ---------------------------------------------------------------------------

def bar(metrics, groupby=None, dttm: str | None = None, time_grain: str = "P1D") -> dict:
    params = {
        "viz_type": "echarts_timeseries_bar",
        "metrics": _as_list(metrics),
        "groupby": _as_list(groupby),
        "adhoc_filters": [],
        "row_limit": 10000,
        "y_axis_format": "SMART_NUMBER",
    }
    # Категориальная ось (без времени) - x_axis = первая колонка groupby.
    if dttm:
        params["x_axis"] = dttm
        params["time_grain_sqla"] = time_grain
    elif groupby:
        params["x_axis"] = _as_list(groupby)[0]
    return params


# ---------------------------------------------------------------------------
# 5. pie - круговая диаграмма
# ---------------------------------------------------------------------------

def pie(metric, groupby) -> dict:
    return {
        "viz_type": "pie",
        "metric": metric,
        "groupby": _as_list(groupby),
        "adhoc_filters": [],
        "row_limit": 100,
        "show_labels_threshold": 5,
        "label_type": "key_value_percent",
        "number_format": "SMART_NUMBER",
        "donut": False,
    }


# ---------------------------------------------------------------------------
# 6. table - таблица (aggregate: groupby+metrics; raw: columns)
# ---------------------------------------------------------------------------

def table(columns=None, metrics=None, groupby=None) -> dict:
    if metrics:
        return {
            "viz_type": "table",
            "query_mode": "aggregate",
            "groupby": _as_list(groupby),
            "metrics": _as_list(metrics),
            "adhoc_filters": [],
            "row_limit": 1000,
            "order_desc": True,
            "table_timestamp_format": "smart_date",
            "show_cell_bars": True,
        }
    return {
        "viz_type": "table",
        "query_mode": "raw",
        "all_columns": _as_list(columns),
        "adhoc_filters": [],
        "row_limit": 1000,
        "order_desc": True,
        "table_timestamp_format": "smart_date",
    }


# ---------------------------------------------------------------------------
# 7. gauge_chart - спидометр (цель задаётся вручную через min_val/max_val)
# ---------------------------------------------------------------------------

def gauge(metric, min_val: float = 0, max_val: float = 100) -> dict:
    return {
        "viz_type": "gauge_chart",
        "metric": metric,
        "groupby": [],
        "adhoc_filters": [],
        "row_limit": 10,
        "start_angle": 225,
        "end_angle": -45,
        "min_val": min_val,
        "max_val": max_val,
        "value_formatter": "{value}",
    }


# ---------------------------------------------------------------------------
# 8. funnel - воронка
# ---------------------------------------------------------------------------

def funnel(metric, groupby) -> dict:
    return {
        "viz_type": "funnel",
        "metric": metric,
        "groupby": _as_list(groupby),
        "adhoc_filters": [],
        "row_limit": 10,
        "number_format": "SMART_NUMBER",
    }


# ---------------------------------------------------------------------------
# 9. heatmap_v2 - тепловая карта
# ---------------------------------------------------------------------------

def heatmap(metric, x: str, y: str) -> dict:
    return {
        "viz_type": "heatmap_v2",
        "metric": metric,
        "x_axis": x,
        "groupby": y,
        "adhoc_filters": [],
        "row_limit": 10000,
        "normalize_across": "heatmap",
        "linear_color_scheme": "blue_white_yellow",
        "sort_x_axis": "alpha_asc",
        "sort_y_axis": "alpha_asc",
    }


# ---------------------------------------------------------------------------
# Сборка полного chart-YAML
# ---------------------------------------------------------------------------

def build_chart(slice_name: str, viz_params: dict, dataset_uuid: str, uuid: str) -> dict:
    """Полный dict chart-ассета. viz_type берётся из viz_params."""
    return {
        "slice_name": slice_name,
        "viz_type": viz_params["viz_type"],
        "params": viz_params,
        "dataset_uuid": dataset_uuid,
        "uuid": uuid,
        "version": "1.0.0",
    }


def dump_chart_yaml(slice_name: str, viz_params: dict, dataset_uuid: str, uuid: str) -> str:
    import yaml

    return yaml.safe_dump(
        build_chart(slice_name, viz_params, dataset_uuid, uuid),
        allow_unicode=True,
        sort_keys=False,
    )


if __name__ == "__main__":
    # Демонстрация: KPI-карточка выручки.
    demo = dump_chart_yaml(
        "Выручка (net)",
        big_number(sql_metric("SUM(CASE WHEN status_id = 5 THEN amount ELSE 0 END)",
                              "Выручка, ₽"),
                   subheader="за период"),
        dataset_uuid="00000000-0000-0000-0000-000000000000",
        uuid="11111111-1111-1111-1111-111111111111",
    )
    print(demo)
