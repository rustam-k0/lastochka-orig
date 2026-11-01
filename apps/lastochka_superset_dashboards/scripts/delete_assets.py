#!/usr/bin/env python
"""Удаление чартов и датасетов из метабазы Superset по uuid (in-process).

Импорт (import_assets.py) только создаёт и обновляет объекты: чарт или датасет,
убранный из YAML, остаётся в Superset сиротой. Этот скрипт удаляет их явно.

Запуск ТОЛЬКО на сервере, в venv Superset:

    SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
      /home/admin/apps/superset/venv/bin/python scripts/delete_assets.py scripts/ops_v1_legacy.txt [--dry-run]

Формат файла: строки «chart <uuid>» или «dataset <uuid>», # - комментарий.
Датасет не удаляется, пока на нём остаются чарты (скрипт сообщает какие).
"""
import pathlib
import sys


def read_list(path: str) -> list[tuple[str, str]]:
    out = []
    for line in pathlib.Path(path).read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if not line:
            continue
        kind, uid = line.split()
        if kind not in ("chart", "dataset"):
            raise SystemExit(f"неизвестный тип {kind!r}")
        out.append((kind, uid))
    return out


def main() -> None:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    dry_run = "--dry-run" in sys.argv
    if len(args) != 1:
        raise SystemExit(__doc__)
    items = read_list(args[0])

    from superset.app import create_app

    app = create_app()
    with app.app_context():
        from superset import db
        from superset.connectors.sqla.models import SqlaTable
        from superset.models.slice import Slice

        charts = [u for k, u in items if k == "chart"]
        datasets = [u for k, u in items if k == "dataset"]
        deleted = 0
        for uid in charts:
            obj = db.session.query(Slice).filter_by(uuid=uid).first()
            if obj is None:
                print(f"chart {uid}: нет в метабазе")
                continue
            print(f"chart {uid}: {obj.slice_name} -> удалить")
            if not dry_run:
                obj.dashboards = []
                db.session.delete(obj)
                deleted += 1
        db.session.flush()
        for uid in datasets:
            obj = db.session.query(SqlaTable).filter_by(uuid=uid).first()
            if obj is None:
                print(f"dataset {uid}: нет в метабазе")
                continue
            left = db.session.query(Slice).filter_by(
                datasource_id=obj.id, datasource_type="table"
            ).all()
            if left:
                names = ", ".join(s.slice_name for s in left)
                print(f"dataset {uid}: {obj.table_name} -> НЕ удалён, есть чарты: {names}")
                continue
            print(f"dataset {uid}: {obj.table_name} -> удалить")
            if not dry_run:
                db.session.delete(obj)
                deleted += 1
        if dry_run:
            db.session.rollback()
            print("dry-run: ничего не удалено")
        else:
            db.session.commit()
            print(f"удалено объектов: {deleted}")


if __name__ == "__main__":
    main()
