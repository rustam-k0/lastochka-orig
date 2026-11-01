#!/usr/bin/env python
"""Генератор дашбордов Superset 6.1.0 из декларативного манифеста (Task 9).

Читает scripts/dashboards_manifest.py (вкладки -> виджеты) и эмитит
superset_assets/dashboards/*.yaml: position_json (GRID -> TABS -> TAB -> ROW ->
CHART, сетка 12 колонок) + metadata.native_filter_configuration («Период» +
фильтры магазина).

Запуск:  python scripts/build_dashboards.py         # генерит оба YAML
         python scripts/build_dashboards.py --check  # только валидация манифеста

--- Как импортёр Superset 6.1.0 читает наш YAML (сверено по venv,
    superset/commands/dashboard/importers/v1/{utils,__init__}.py) ---

* Чарты крепятся к дашборду по position: build_uuid_to_id_map берёт
  child["meta"]["uuid"] и child["meta"]["chartId"]. chartId ОБЯЗАН присутствовать
  (иначе KeyError) - ставим уникальный placeholder-int на каждый CHART-узел.
  Импортёр перезапишет chartId реальным id слайса (update_id_refs).
* find_chart_uuids(position) -> все uuid из position попадают в dashboard_slices.
* native_filter targets используют datasetUuid (НЕ числовой datasetId):
  find_native_filter_datasets собирает target["datasetUuid"], update_id_refs
  делает target["datasetId"] = dataset_info[datasetUuid]["datasource_id"].
* scope.excluded - числовые chartId; update_id_refs ремапит их через
  id_map = {placeholder_chartId -> реальный_id}. Поэтому в excluded кладём те же
  placeholder-int, что и в CHART.meta.chartId (совпадение по chart_uuid).
* migrate_dashboard() вызывается на импортируемом дашборде, но: он делает
  setdefault("native_filter_configuration", []) + extend(конверсия filter_box).
  filter_box у нас нет, filter_scopes нет -> наши native-фильтры сохраняются как есть.

--- Раскладка ---
Виджеты одного kind пакуются в ряды (KIND_SPEC: ширина, высота, сколько в ряд).
KPI/gauge - по 4 в ряд (ширина 3, высота 28); funnel/pie/bubble/pivot - по 2
(ширина 6, высота 44); line/bar/heatmap/table - на всю ширину (12, высота 50);
wide (светофор/лента) - 12 высотой 60. Ряд закрывается при смене kind или заполнении.

--- Фильтры магазина (решение по колонкам, зафиксировано) ---
shop_id есть ТОЛЬКО у db1 (курьерка); store_id есть у db2 (int) И db3 (varchar) -
разные типы и разные домены значений (db3 store_id = shop_code 'default'/'djami_store',
не маппится на курьерский shop_id). Один select-фильтр таргетит одну колонку одного
датасета и применяется к чартам, где колонка с ЭТИМ ИМЕНЕМ присутствует. Отсюда:
  * «Магазин» (shop_id, db1) - scope на весь дашборд безопасен: ни один db2/db3
    датасет не имеет колонки shop_id, фильтр их не трогает. single-select
    (требование леджера: MAX-опекс корректен только при выборе одного магазина).
  * «Магазин (сторфронт)» (store_id, db2 int) и «Магазин (события)» (store_id,
    db3 varchar) - оба матчатся по имени store_id, поэтому взаимно ИСКЛЮЧАЮТ чарты
    чужого домена через scope.excluded (иначе int-фильтр сломал бы varchar-датасет
    и наоборот). Каждый включается только если на дашборде есть чарты его домена.
Датасет db2 store_customers_fact не имеет store_id вовсе (проверено в метабазе
Superset) - его чарты не фильтруются магазином ни одним из фильтров, только «Период».
"""
from __future__ import annotations

import hashlib
import pathlib
import re
import sys

import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
ASSETS = ROOT / "superset_assets"
CHARTS_DIR = ASSETS / "charts"
DATASETS_DIR = ASSETS / "datasets"
OUT_DIR = ASSETS / "dashboards"

# database uuid трёх подключений (README: databases/*.yaml).
DB1 = "5fe2883e-9e10-44ad-8cb5-22305f923603"  # lastochka_djami_copy (курьерка, MySQL)
DB2 = "22c13af4-950d-4550-9f16-1617f4df8d2f"  # djami_store_copy (storefront, MySQL)
DB3 = "43a9433a-6c4d-4350-98ba-c4ccf663080b"  # lastochka_analytics (события, Postgres)
DB4 = "cf57c09a-89dc-4014-ad28-5dc33efa8cd9"  # lastochka_djami (курьерка, ЖИВАЯ, MySQL)

# Датасеты db2/db3, у которых нет колонки store_id (проверено в метабазе Superset).
# store_customers_fact отдаёт registered_at/first_order_store_id, но НЕ store_id ->
# фильтром магазина не накрывается.
NO_STORE_COL = {"store_customers_fact"}

GRID_COLUMNS = 12

# kind -> (width, height, per_row)
# height - grid-юниты Superset 6 (~8px/юнит), значения сверены по практике нативных
# бордов: kpi/gauge ~28-30, funnel/pie/bubble/pivot ~44, line/bar/heatmap/table ~50,
# широкие таблицы (Светофор/Лента) ~60. Прежние 4/8/12 давали схлопнутые контейнеры
# (Task 10 UI-находка): тело big_number не влезало, таблицы обрезались после шапки.
KIND_SPEC = {
    "kpi": (3, 28, 4),
    "gauge": (3, 28, 4),
    "funnel": (6, 44, 2),
    "pie": (6, 44, 2),
    "bubble": (6, 44, 2),
    "pivot": (6, 44, 2),
    "line": (12, 50, 1),
    "bar": (12, 50, 1),
    "heatmap": (12, 50, 1),
    "table": (12, 50, 1),
    "wide": (12, 60, 1),
}


def _nid(prefix: str, dashboard_uuid: str, key) -> str:
    """Детерминированный id узла position (стабилен между прогонами генератора,
    уникален внутри борда): sha1(dashboard_uuid:prefix:key) вместо uuid4()."""
    material = f"{dashboard_uuid}:{prefix}:{key}"
    digest = hashlib.sha1(material.encode("utf-8")).hexdigest()
    return f"{prefix}-{digest[:10]}"


def _short_filter_id(dashboard_uuid: str, key) -> str:
    material = f"{dashboard_uuid}:NATIVE_FILTER:{key}"
    digest = hashlib.sha1(material.encode("utf-8")).hexdigest()
    return f"NATIVE_FILTER-{digest[:9]}"


def load_charts() -> dict:
    """chart_uuid -> {dataset_uuid, slice_name}."""
    out = {}
    for p in sorted(CHARTS_DIR.glob("*.yaml")):
        doc = yaml.safe_load(p.read_text(encoding="utf-8"))
        out[str(doc["uuid"])] = {
            "dataset_uuid": str(doc["dataset_uuid"]),
            "slice_name": doc.get("slice_name", ""),
        }
    return out


def load_datasets() -> dict:
    """dataset_uuid -> {database_uuid, table_name, domain, shop_col}.

    domain: courier|storefront|events. shop_col: колонка-магазин для native-фильтра
    (shop_id/store_id) или None, если колонки нет.
    """
    out = {}
    for p in sorted(DATASETS_DIR.rglob("*.yaml")):
        doc = yaml.safe_load(p.read_text(encoding="utf-8"))
        dbu = str(doc["database_uuid"])
        table = doc.get("table_name", p.stem)
        if dbu in (DB1, DB4):
            domain, shop_col = "courier", "shop_id"
        elif dbu == DB2:
            domain = "storefront"
            shop_col = None if table in NO_STORE_COL else "store_id"
        elif dbu == DB3:
            domain, shop_col = "events", "store_id"
        else:
            domain, shop_col = "unknown", None
        out[str(doc["uuid"])] = {
            "database_uuid": dbu,
            "table_name": table,
            "domain": domain,
            "shop_col": shop_col,
        }
    return out


def pack_rows(widgets: list[tuple[str, str]]) -> list[list[dict]]:
    """Пакует упорядоченный список (uuid, kind) в ряды по правилам KIND_SPEC."""
    rows: list[list[dict]] = []
    cur: list[dict] = []
    cur_kind = None
    for cu, kind in widgets:
        if kind not in KIND_SPEC:
            raise ValueError(f"неизвестный kind {kind!r} для чарта {cu}")
        width, height, per_row = KIND_SPEC[kind]
        item = {"uuid": cu, "kind": kind, "width": width, "height": height}
        if cur and (kind != cur_kind or len(cur) >= per_row):
            rows.append(cur)
            cur = []
        cur.append(item)
        cur_kind = kind
    if cur:
        rows.append(cur)
    return rows


def build_position(manifest: dict, charts: dict, next_chart_id) -> tuple[dict, dict]:
    """position_json (schema v2) + карта chart_uuid -> placeholder chartId."""
    title = manifest["dashboard_title"]
    dashboard_uuid = str(manifest["uuid"])
    position: dict = {
        "DASHBOARD_VERSION_KEY": "v2",
        "ROOT_ID": {"type": "ROOT", "id": "ROOT_ID", "children": ["GRID_ID"]},
        "GRID_ID": {
            "type": "GRID",
            "id": "GRID_ID",
            "children": [],
            "parents": ["ROOT_ID"],
        },
        # HEADER_ID у Superset - штатный standalone-узел (не входит в children ROOT/
        # GRID); хранит заголовок дашборда. Так генерит и сам Superset для нового борда.
        "HEADER_ID": {
            "type": "HEADER",
            "id": "HEADER_ID",
            "meta": {"text": title},
        },
    }
    uuid_to_placeholder: dict[str, int] = {}

    tabs_id = _nid("TABS", dashboard_uuid, "tabs")
    position[tabs_id] = {
        "type": "TABS",
        "id": tabs_id,
        "meta": {},
        "children": [],
        "parents": ["ROOT_ID", "GRID_ID"],
    }
    position["GRID_ID"]["children"].append(tabs_id)

    for tab_index, tab in enumerate(manifest["tabs"]):
        tab_id = _nid("TAB", dashboard_uuid, tab_index)
        position[tab_id] = {
            "type": "TAB",
            "id": tab_id,
            "meta": {
                "text": tab["title"],
                "defaultText": "Tab title",
                "placeholder": "Tab title",
            },
            "children": [],
            "parents": ["ROOT_ID", "GRID_ID", tabs_id],
        }
        position[tabs_id]["children"].append(tab_id)

        for row_index, row in enumerate(pack_rows(tab["widgets"])):
            row_id = _nid("ROW", dashboard_uuid, f"{tab_index}:{row_index}")
            position[row_id] = {
                "type": "ROW",
                "id": row_id,
                "meta": {"background": "BACKGROUND_TRANSPARENT"},
                "children": [],
                "parents": ["ROOT_ID", "GRID_ID", tabs_id, tab_id],
            }
            position[tab_id]["children"].append(row_id)

            for item in row:
                cu = item["uuid"]
                if cu not in charts:
                    raise ValueError(f"чарт {cu} не найден в superset_assets/charts/")
                if cu in uuid_to_placeholder:
                    raise ValueError(f"чарт {cu} размещён дважды на дашборде {title!r}")
                pid = next_chart_id()
                uuid_to_placeholder[cu] = pid
                chart_id = _nid("CHART", dashboard_uuid, cu)
                position[chart_id] = {
                    "type": "CHART",
                    "id": chart_id,
                    "meta": {
                        "uuid": cu,
                        "chartId": pid,
                        "width": item["width"],
                        "height": item["height"],
                        "sliceName": charts[cu]["slice_name"],
                    },
                    "parents": [
                        "ROOT_ID", "GRID_ID", tabs_id, tab_id, row_id,
                    ],
                }
                position[row_id]["children"].append(chart_id)

    return position, uuid_to_placeholder


# --- Раскладка «отчётов» («Операционные отчёты», ТЗ v2.1) -------------------
# Вкладка -> вложенные вкладки отчётов R1..Rn -> позиции П1-П7 по сетке 12 колонок:
# П1 шапка (markdown), П2 KPI (4 по 3 колонки или 6 по 4 в два ряда), П3-П5 чарты
# с явной шириной, таблица (или вложенные подтабы таблиц), П6 примечание,
# П7 методика (markdown, свёрнута в <details>).
REPORT_HEIGHT = {"kpi": 24, "chart": 50, "table": 75, "map": 65}
MD_HEIGHT = {"header": 9, "note": 6, "method": 7}


def build_reports_position(manifest: dict, charts: dict, next_chart_id):
    """position_json + chart_uuid -> placeholder + имя вкладки/код отчёта -> id TAB."""
    title = manifest["dashboard_title"]
    du = str(manifest["uuid"])
    position: dict = {
        "DASHBOARD_VERSION_KEY": "v2",
        "ROOT_ID": {"type": "ROOT", "id": "ROOT_ID", "children": ["GRID_ID"]},
        "GRID_ID": {"type": "GRID", "id": "GRID_ID", "children": [], "parents": ["ROOT_ID"]},
        "HEADER_ID": {"type": "HEADER", "id": "HEADER_ID", "meta": {"text": title}},
    }
    uuid_to_placeholder: dict[str, int] = {}
    scope_ids: dict[str, str] = {}

    def add(node_type, key, parent_id, meta, parents):
        nid = _nid(node_type, du, key)
        node = {"type": node_type, "id": nid, "children": [], "parents": parents, "meta": meta}
        position[nid] = node
        position[parent_id]["children"].append(nid)
        return nid, parents + [nid]

    def tab_meta(text):
        return {"text": text, "defaultText": "Tab title", "placeholder": "Tab title"}

    def add_chart(cu, width, height, row_id, row_parents):
        if cu not in charts:
            raise ValueError(f"чарт {cu} не найден в superset_assets/charts/")
        if cu in uuid_to_placeholder:
            raise ValueError(f"чарт {cu} размещён дважды на дашборде {title!r}")
        pid = next_chart_id()
        uuid_to_placeholder[cu] = pid
        cid = _nid("CHART", du, cu)
        position[cid] = {
            "type": "CHART",
            "id": cid,
            "children": [],
            "parents": row_parents,
            "meta": {
                "uuid": cu,
                "chartId": pid,
                "width": width,
                "height": height,
                "sliceName": charts[cu]["slice_name"],
            },
        }
        position[row_id]["children"].append(cid)

    def add_row(key, parent_id, parents):
        return add("ROW", key, parent_id, {"background": "BACKGROUND_TRANSPARENT"}, parents)

    def add_markdown(key, text, height, parent_id, parents):
        row_id, row_parents = add_row(f"{key}:row", parent_id, parents)
        mid = _nid("MARKDOWN", du, key)
        position[mid] = {
            "type": "MARKDOWN",
            "id": mid,
            "children": [],
            "parents": row_parents,
            "meta": {"width": GRID_COLUMNS, "height": height, "code": text},
        }
        position[row_id]["children"].append(mid)

    def add_kpis(key, uuids, parent_id, parents):
        per_row = 4 if len(uuids) <= 4 else 3
        width = GRID_COLUMNS // per_row
        for i in range(0, len(uuids), per_row):
            row_id, row_parents = add_row(f"{key}:kpi:{i}", parent_id, parents)
            for cu in uuids[i:i + per_row]:
                add_chart(cu, width, REPORT_HEIGHT["kpi"], row_id, row_parents)

    tabs_id, tabs_parents = add("TABS", "tabs", "GRID_ID", {}, ["ROOT_ID", "GRID_ID"])
    for tab in manifest["report_tabs"]:
        tab_id, tab_parents = add("TAB", f"tab:{tab['name']}", tabs_id, tab_meta(tab["title"]), tabs_parents)
        scope_ids[tab["name"]] = tab_id
        if "dq" in tab:
            dq = tab["dq"]
            add_markdown("dq:header", dq["header"], MD_HEIGHT["header"], tab_id, tab_parents)
            add_kpis("dq", dq["kpis"], tab_id, tab_parents)
            row_id, row_parents = add_row("dq:table", tab_id, tab_parents)
            add_chart(dq["table"], GRID_COLUMNS, REPORT_HEIGHT["table"], row_id, row_parents)
            add_markdown("dq:note", dq["note"], MD_HEIGHT["note"], tab_id, tab_parents)
            continue
        rtabs_id, rtabs_parents = add("TABS", f"rtabs:{tab['name']}", tab_id, {}, tab_parents)
        for rep in tab["reports"]:
            code = rep["code"]
            rtab_id, rtab_parents = add("TAB", f"rep:{code}", rtabs_id, tab_meta(rep["title"]), rtabs_parents)
            scope_ids[code] = rtab_id
            add_markdown(f"{code}:header", rep["header"], MD_HEIGHT["header"], rtab_id, rtab_parents)
            add_kpis(code, rep["kpis"], rtab_id, rtab_parents)
            for ri, row in enumerate(rep["rows"]):
                row_id, row_parents = add_row(f"{code}:charts:{ri}", rtab_id, rtab_parents)
                for cu, width, kind in row:
                    add_chart(cu, width, REPORT_HEIGHT.get(kind, REPORT_HEIGHT["chart"]), row_id, row_parents)
            if rep["table"]:
                row_id, row_parents = add_row(f"{code}:table", rtab_id, rtab_parents)
                add_chart(rep["table"], GRID_COLUMNS, REPORT_HEIGHT["table"], row_id, row_parents)
            if rep["table_tabs"]:
                ttabs_id, ttabs_parents = add("TABS", f"{code}:ttabs", rtab_id, {}, rtab_parents)
                for ti, (ttitle, cu) in enumerate(rep["table_tabs"]):
                    ttab_id, ttab_parents = add("TAB", f"{code}:ttab:{ti}", ttabs_id, tab_meta(ttitle), ttabs_parents)
                    row_id, row_parents = add_row(f"{code}:ttab:{ti}:row", ttab_id, ttab_parents)
                    add_chart(cu, GRID_COLUMNS, REPORT_HEIGHT["table"], row_id, row_parents)
            add_markdown(f"{code}:note", rep["note"], MD_HEIGHT["note"], rtab_id, rtab_parents)
            add_markdown(f"{code}:method", rep["method"], MD_HEIGHT["method"], rtab_id, rtab_parents)
    return position, uuid_to_placeholder, scope_ids


def manifest_chart_uuids(m: dict) -> list[str]:
    """Все чарты дашборда в порядке размещения (для валидации)."""
    if m.get("layout") != "reports":
        return [cu for tab in m["tabs"] for cu, _ in tab["widgets"]]
    out = []
    for tab in m["report_tabs"]:
        if "dq" in tab:
            out += tab["dq"]["kpis"] + [tab["dq"]["table"]]
            continue
        for rep in tab["reports"]:
            out += rep["kpis"]
            out += [cu for row in rep["rows"] for cu, _, _ in row]
            if rep["table"]:
                out.append(rep["table"])
            out += [cu for _, cu in rep["table_tabs"]]
    return out


def _select_control_values() -> dict:
    # single-select: multiSelect=False (леджер MAX-опекс корректен на одном магазине).
    return {
        "enableEmptyFilter": False,
        "defaultToFirstItem": False,
        "multiSelect": False,
        "searchAllOptions": False,
        "inverseSelection": False,
    }


def _shop_filter(
    dashboard_uuid: str, name: str, dataset_uuid: str, column: str, excluded: list[int]
) -> dict:
    return {
        "id": _short_filter_id(dashboard_uuid, name),
        "name": name,
        "filterType": "filter_select",
        "type": "NATIVE_FILTER",
        "targets": [{"datasetUuid": dataset_uuid, "column": {"name": column}}],
        "controlValues": _select_control_values(),
        "cascadeParentIds": [],
        "scope": {"rootPath": ["ROOT_ID"], "excluded": excluded},
        "defaultDataMask": {"filterState": {}, "extraFormData": {}},
        "sortMetric": None,
        "description": "",
    }


def build_manifest_filters(manifest: dict, datasets: dict, scope_ids: dict | None = None) -> list[dict]:
    """Фильтры, перечисленные в манифесте дашборда (ключ "filters").

    Формат элемента:
      {"type": "time", "name": "Период", "default": "Last month"}
      {"type": "select", "name": "Товар", "dataset": "<table_name>",
       "column": "<колонка>", "multi": True, "search_all": True}

    select-фильтр берёт значения из колонки указанного датасета и действует на
    все чарты дашборда, в датасете которых есть колонка с ТЕМ ЖЕ именем (у
    остальных Superset его не применяет). Поэтому в датасетах «Операционных
    отчётов» общие измерения названы одинаково: shop_name, product_name,
    courier_name, picker_name, zone_name.
    """
    dashboard_uuid = str(manifest["uuid"])
    by_table = {ds["table_name"]: du for du, ds in datasets.items()}
    out: list[dict] = []

    def scope_of(spec) -> dict:
        names = spec.get("scope")
        if not names:
            return {"rootPath": ["ROOT_ID"], "excluded": []}
        missing = [n for n in names if n not in (scope_ids or {})]
        if missing:
            raise ValueError(f"фильтр {spec['name']!r}: нет вкладки/отчёта {missing}")
        return {"rootPath": [scope_ids[n] for n in names], "excluded": []}

    for spec in manifest["filters"]:
        if spec["type"] == "time":
            default = spec.get("default")
            mask = {"filterState": {}, "extraFormData": {}}
            if default:
                mask = {
                    "filterState": {"value": default},
                    "extraFormData": {"time_range": default},
                }
            out.append(
                {
                    "id": _short_filter_id(dashboard_uuid, "period"),
                    "name": spec["name"],
                    "filterType": "filter_time",
                    "type": "NATIVE_FILTER",
                    "targets": [{}],
                    "controlValues": {},
                    "cascadeParentIds": [],
                    "scope": {"rootPath": ["ROOT_ID"], "excluded": []},
                    "defaultDataMask": mask,
                    "description": spec.get("description", ""),
                }
            )
            continue
        if spec["type"] != "select":
            raise ValueError(f"неизвестный тип фильтра {spec['type']!r}")
        table = spec["dataset"]
        if table not in by_table:
            raise ValueError(f"фильтр {spec['name']!r}: датасет {table!r} не найден")
        mask = {"filterState": {}, "extraFormData": {}}
        if spec.get("default") is not None:
            val = [spec["default"]]
            mask = {
                "filterState": {"value": val, "label": spec["default"]},
                "extraFormData": {"filters": [{"col": spec["column"], "op": "IN", "val": val}]},
            }
        out.append(
            {
                "id": _short_filter_id(dashboard_uuid, spec["column"]),
                "name": spec["name"],
                "filterType": "filter_select",
                "type": "NATIVE_FILTER",
                "targets": [
                    {"datasetUuid": by_table[table], "column": {"name": spec["column"]}}
                ],
                "controlValues": {
                    "enableEmptyFilter": bool(spec.get("required", False)),
                    "defaultToFirstItem": False,
                    "multiSelect": bool(spec.get("multi", True)),
                    "searchAllOptions": bool(spec.get("search_all", False)),
                    "inverseSelection": False,
                },
                "cascadeParentIds": [],
                "scope": scope_of(spec),
                "defaultDataMask": mask,
                "sortMetric": None,
                "description": spec.get("description", ""),
            }
        )
    return out


def build_native_filters(
    manifest: dict, charts: dict, datasets: dict, uuid_to_placeholder: dict,
    scope_ids: dict | None = None,
) -> list[dict]:
    """«Период» (filter_time) + фильтры магазина по доменам, присутствующим на борде.

    Если в манифесте дашборда задан ключ "filters" - берутся ровно они
    (см. build_manifest_filters).
    """
    if manifest.get("filters"):
        return build_manifest_filters(manifest, datasets, scope_ids)
    dashboard_uuid = str(manifest["uuid"])
    # Разложим чарты дашборда по доменам колонки-магазина.
    used = list(uuid_to_placeholder)
    domain_of = {}  # chart_uuid -> ('courier'|'storefront'|'events', shop_col)
    for cu in used:
        ds = datasets[charts[cu]["dataset_uuid"]]
        domain_of[cu] = (ds["domain"], ds["shop_col"])

    def first_dataset_uuid(pred) -> str | None:
        for cu in used:
            ds = datasets[charts[cu]["dataset_uuid"]]
            if pred(ds):
                return charts[cu]["dataset_uuid"]
        return None

    filters: list[dict] = []

    # «Период» - filter_time, весь дашборд, по main_dttm_col каждого датасета.
    filters.append(
        {
            "id": _short_filter_id(dashboard_uuid, "period"),
            "name": "Период",
            "filterType": "filter_time",
            "type": "NATIVE_FILTER",
            "targets": [{}],
            "controlValues": {},
            "cascadeParentIds": [],
            "scope": {"rootPath": ["ROOT_ID"], "excluded": []},
            "defaultDataMask": {"filterState": {}, "extraFormData": {}},
            "description": "Диапазон дат по временной колонке каждого датасета "
            "(main_dttm_col).",
        }
    )

    # «Магазин» (курьерка, shop_id, db1) - безопасен на весь борд (shop_id только у db1).
    ds_shop = first_dataset_uuid(lambda ds: ds["shop_col"] == "shop_id")
    if ds_shop:
        filters.append(
            _shop_filter(dashboard_uuid, "Магазин", ds_shop, "shop_id", excluded=[])
        )

    # store_id есть у db2 (int) и db3 (varchar) -> два взаимно исключающих фильтра.
    store2_charts = [
        uuid_to_placeholder[cu]
        for cu in used
        if domain_of[cu] == ("storefront", "store_id")
    ]
    store3_charts = [
        uuid_to_placeholder[cu]
        for cu in used
        if domain_of[cu] == ("events", "store_id")
    ]

    if store2_charts:
        ds2 = first_dataset_uuid(
            lambda ds: ds["domain"] == "storefront" and ds["shop_col"] == "store_id"
        )
        # исключаем db3-чарты (varchar store_id), чтобы int-фильтр их не ломал.
        filters.append(
            _shop_filter(
                dashboard_uuid,
                "Магазин (сторфронт)",
                ds2,
                "store_id",
                excluded=sorted(store3_charts),
            )
        )
    if store3_charts:
        ds3 = first_dataset_uuid(
            lambda ds: ds["domain"] == "events" and ds["shop_col"] == "store_id"
        )
        # исключаем db2-чарты (int store_id), чтобы varchar-фильтр их не ломал.
        filters.append(
            _shop_filter(
                dashboard_uuid,
                "Магазин (события)",
                ds3,
                "store_id",
                excluded=sorted(store2_charts),
            )
        )

    return filters


def build_dashboard_config(manifest: dict, charts: dict, datasets: dict) -> dict:
    counter = {"n": 0}

    def next_chart_id() -> int:
        counter["n"] += 1
        return counter["n"]

    scope_ids = None
    if manifest.get("layout") == "reports":
        position, uuid_to_placeholder, scope_ids = build_reports_position(
            manifest, charts, next_chart_id
        )
    else:
        position, uuid_to_placeholder = build_position(manifest, charts, next_chart_id)
    native_filters = build_native_filters(
        manifest, charts, datasets, uuid_to_placeholder, scope_ids
    )
    # Подзаголовок панели = описание чарта, раскрытое под заголовком.
    expanded = {
        str(uuid_to_placeholder[cu]): True
        for cu in manifest.get("expanded", [])
        if cu in uuid_to_placeholder
    }
    metadata = {
        "color_scheme": manifest.get("color_scheme", "supersetColors"),
        "refresh_frequency": 0,
        "expanded_slices": expanded,
        "label_colors": {},
        "shared_label_colors": [],
        "color_scheme_domain": [],
        "cross_filters_enabled": True,
        "default_filters": "{}",
        "filter_scopes": {},
        "chart_configuration": {},
        "global_chart_configuration": {
            "scope": {"rootPath": ["ROOT_ID"], "excluded": []},
            "chartsInScope": [],
        },
        "native_filter_configuration": native_filters,
    }
    return {
        "dashboard_title": manifest["dashboard_title"],
        "description": None,
        "css": manifest.get("css", ""),
        "slug": manifest.get("slug"),
        "uuid": manifest["uuid"],
        "position": position,
        "metadata": metadata,
        "version": "1.0.0",
        "is_managed_externally": False,
        "published": True,
    }


def emit(manifest: dict, charts: dict, datasets: dict) -> pathlib.Path:
    config = build_dashboard_config(manifest, charts, datasets)
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    # Читаемое имя файла: Analytics_By_Shop.yaml / Analytics_By_Network.yaml
    fname = "_".join(w.capitalize() for w in manifest["slug"].split("-")) + ".yaml"
    out = OUT_DIR / fname
    out.write_text(
        yaml.safe_dump(config, allow_unicode=True, sort_keys=False, width=1000),
        encoding="utf-8",
    )
    return out


def main() -> None:
    check_only = "--check" in sys.argv[1:]
    from dashboards_manifest import DASHBOARDS

    charts = load_charts()
    datasets = load_datasets()

    # Валидация: все виджеты существуют и не дублируются на борде.
    seen_global = set()
    total = 0
    for m in DASHBOARDS:
        per_board = set()
        for cu in manifest_chart_uuids(m):
            total += 1
            if cu not in charts:
                raise SystemExit(f"чарт {cu} нет в charts/")
            if cu in per_board:
                raise SystemExit(f"чарт {cu} дважды на борде {m['dashboard_title']}")
            per_board.add(cu)
            seen_global.add(cu)
    print(f"widgets в манифесте: {total}; уникальных чартов: {len(seen_global)}")
    missing = set(charts) - seen_global
    if missing:
        print(f"ВНИМАНИЕ: чарты не размещены ({len(missing)}): {sorted(missing)}")
    else:
        print(f"все {len(charts)} чартов репозитория размещены")

    if check_only:
        return

    for m in DASHBOARDS:
        out = emit(m, charts, datasets)
        cfg = build_dashboard_config(m, charts, datasets)
        n_charts = sum(
            1
            for v in cfg["position"].values()
            if isinstance(v, dict) and v.get("type") == "CHART"
        )
        n_filters = len(cfg["metadata"]["native_filter_configuration"])
        fnames = [f["name"] for f in cfg["metadata"]["native_filter_configuration"]]
        print(
            f"{out.name}: {n_charts} чартов, {n_filters} фильтров {fnames}"
        )


if __name__ == "__main__":
    main()
