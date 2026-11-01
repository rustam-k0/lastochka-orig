#!/usr/bin/env python
"""Рендер Jinja-SQL датасетов «Операционных отчётов» вне Superset.

Нужен для проверки SQL на копиях БД до импорта: подставляет период и магазин
так же, как это делают get_time_filter / filter_values в Superset.

    python scripts/ops_render.py ops_abc_products --from 2026-09-21 --to 2026-10-22
    python scripts/ops_render.py ops_abc_products --shop "Ласточка Джами"
    python scripts/ops_render.py --list
"""
from __future__ import annotations

import argparse
import sys

import jinja2

import ops_datasets


class _TimeFilter:
    def __init__(self, since: str | None, until: str | None):
        self.from_expr = f"'{since} 00:00:00'" if since else None
        self.to_expr = f"'{until} 00:00:00'" if until else None


def _where_in(values) -> str:
    quoted = ", ".join("'" + str(v).replace("'", "''") + "'" for v in values)
    return f"({quoted})"


def render(table: str, since: str | None, until: str | None, shops: list[str]) -> str:
    ds = next(d for d in ops_datasets.DATASETS if d["table_name"] == table)
    env = jinja2.Environment()
    env.filters["where_in"] = _where_in
    tmpl = env.from_string(ds["sql"])
    return tmpl.render(
        get_time_filter=lambda *a, **k: _TimeFilter(since, until),
        filter_values=lambda column, *a, **k: shops if column == "shop_name" else [],
    )


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("table", nargs="?")
    ap.add_argument("--from", dest="since")
    ap.add_argument("--to", dest="until")
    ap.add_argument("--shop", action="append", default=[])
    ap.add_argument("--list", action="store_true")
    args = ap.parse_args()
    if args.list or not args.table:
        for d in ops_datasets.DATASETS:
            print(d["table_name"], d["database_uuid"])
        return
    sys.stdout.write(render(args.table, args.since, args.until, args.shop))


if __name__ == "__main__":
    main()
