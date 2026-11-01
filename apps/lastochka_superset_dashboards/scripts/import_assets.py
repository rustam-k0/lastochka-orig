#!/usr/bin/env python
"""Импорт superset_assets в Superset (in-process, минуя HTTP).

Запуск ТОЛЬКО на сервере, в venv Superset:

    SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
      /home/admin/apps/superset/venv/bin/python scripts/import_assets.py

Что делает:
  1. Собирает contents (все superset_assets/**/*.yaml + сгенерированный metadata.yaml).
  2. Выставляет g.user = admin (ImportAssetsCommand требует прав can_write; без
     залогиненного пользователя in-process импорт падает на проверке прав).
  3. Прогоняет ImportAssetsCommand(contents, passwords=...).run() (overwrite по uuid,
     идемпотентно: повторный запуск обновляет объекты, не плодит дублей).
  4. Обновляет колонки СВОИХ виртуальных датасетов через fetch_metadata()
     (только по uuid из contents - чужие датасеты метабазы не трогаем).

Пароли БД:
  Не нужны, пока databases/*.yaml ссылаются на СУЩЕСТВУЮЩИЕ подключения по uuid -
  load_configs подставит пароль из метабазы. Для НОВОГО подключения передать пароль
  через env:  SUPERSET_ASSET_PASSWORDS='{"databases/foo.yaml": "secret"}'
  (в git пароли не коммитить).

Факт по Superset 6.1.0 (сверено по venv):
  ImportAssetsCommand.__init__(self, contents: dict[str, str], *args, **kwargs)
    kwargs: passwords, ssh_tunnel_passwords, ssh_tunnel_private_keys,
            ssh_tunnel_priv_key_passwords, encrypted_extra_secrets, sparse
  .run() = validate() + _import(); порядок импорта databases → queries → datasets →
  charts → dashboards; матч по uuid; metadata.yaml обязателен (version=1.0.0, type=assets).
"""
import json
import os
import pathlib
import sys

ASSETS = pathlib.Path(__file__).resolve().parent.parent / "superset_assets"
TIMESTAMP = "2026-07-17T00:00:00+00:00"


def build_contents():
    """Читает все YAML ассетов + добавляет обязательный metadata.yaml.

    Ключи - POSIX-относительные пути с префиксом каталога (databases/, datasets/,
    charts/, dashboards/), как ждёт ImportAssetsCommand (schema матчится по префиксу).
    """
    contents = {}
    for p in sorted(ASSETS.rglob("*.yaml")):
        rel = p.relative_to(ASSETS).as_posix()
        contents[rel] = p.read_text(encoding="utf-8")
    contents["metadata.yaml"] = (
        f"version: 1.0.0\ntype: assets\ntimestamp: \"{TIMESTAMP}\"\n"
    )
    return contents


def build_passwords():
    """Опциональные пароли для НОВЫХ подключений (env SUPERSET_ASSET_PASSWORDS, JSON).

    Для уже существующих подключений (наш случай) - пусто: пароль берётся из метабазы.
    """
    raw = os.environ.get("SUPERSET_ASSET_PASSWORDS")
    return json.loads(raw) if raw else {}


def dataset_uuids(contents):
    """UUID датасетов из contents - чтобы fetch_metadata() трогал только наши."""
    import yaml

    uuids = set()
    for name, content in contents.items():
        if name.startswith("datasets/"):
            doc = yaml.safe_load(content)
            if doc and doc.get("uuid"):
                uuids.add(str(doc["uuid"]))
    return uuids


def main():
    from superset.app import create_app

    app = create_app()
    with app.app_context():
        from flask import g

        from superset import db, security_manager
        from superset.commands.importers.v1.assets import ImportAssetsCommand
        from superset.connectors.sqla.models import SqlaTable

        admin = security_manager.find_user(username="admin")
        if admin is None:
            print("import: FAIL - пользователь 'admin' не найден в метабазе Superset")
            sys.exit(2)
        g.user = admin

        contents = build_contents()
        passwords = build_passwords()

        n_db = sum(1 for k in contents if k.startswith("databases/"))
        n_ds = sum(1 for k in contents if k.startswith("datasets/"))
        n_ch = sum(1 for k in contents if k.startswith("charts/"))
        n_dash = sum(1 for k in contents if k.startswith("dashboards/"))
        print(
            f"assets: {n_db} databases, {n_ds} datasets, {n_ch} charts, "
            f"{n_dash} dashboards"
        )

        ImportAssetsCommand(contents, passwords=passwords).run()
        print("import: OK")

        # Обновление колонок виртуальных датасетов из их SQL (только наши, по uuid).
        # request-контекст нужен Jinja-датасетам: макросы get_time_filter /
        # filter_values читают form_data через flask.request.
        our_uuids = dataset_uuids(contents)
        failed = []
        refreshed = 0
        with app.test_request_context():
            g.user = admin
            for tbl in db.session.query(SqlaTable).all():
                if str(tbl.uuid) not in our_uuids:
                    continue
                try:
                    tbl.fetch_metadata()
                    refreshed += 1
                except Exception as e:  # noqa: BLE001
                    failed.append((tbl.table_name, str(e)[:200]))
            db.session.commit()

        for name, err in failed:
            print(f"column refresh FAILED: {name}: {err}")
        print(
            f"fetch_metadata: {'OK' if not failed else str(len(failed)) + ' failed'} "
            f"(refreshed {refreshed}/{len(our_uuids)})"
        )
        if failed:
            sys.exit(1)


if __name__ == "__main__":
    main()
