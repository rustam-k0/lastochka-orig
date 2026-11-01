#!/usr/bin/env python
"""Валидатор SQL виртуальных датасетов.

Для каждого superset_assets/datasets/**/*.yaml выполняет
    SELECT * FROM (<sql>) t LIMIT 3
через engine соответствующего подключения (берётся из метабазы Superset по
database_uuid - реальный пароль подставляется Superset'ом сам). Печатает по строке:
    <table_name>: OK rows=N cols=[...]
или
    <table_name>: FAIL <ошибка>

Запуск ТОЛЬКО на сервере, в venv Superset:

    SUPERSET_CONFIG_PATH=/home/admin/apps/superset/superset_config.py \
      /home/admin/apps/superset/venv/bin/python scripts/run_dataset_sql.py [подстрока]

Необязательный аргумент [подстрока] фильтрует по имени файла датасета
(например `djami_orders` - прогнать только совпадающие).

Exit code: 0 если все OK, 1 если хоть один FAIL.
"""
import pathlib
import sys

ASSETS = pathlib.Path(__file__).resolve().parent.parent / "superset_assets"
DATASETS = ASSETS / "datasets"


def iter_datasets(filter_substr=None):
    import yaml

    for p in sorted(DATASETS.rglob("*.yaml")):
        if p.name == ".gitkeep":
            continue
        if filter_substr and filter_substr not in p.name:
            continue
        doc = yaml.safe_load(p.read_text(encoding="utf-8"))
        if not doc or not doc.get("sql"):
            continue
        yield p, doc


def main():
    filter_substr = sys.argv[1] if len(sys.argv) > 1 else None

    from sqlalchemy import text

    from superset.app import create_app

    app = create_app()
    all_ok = True
    n = 0
    with app.app_context():
        from superset import db
        from superset.models.core import Database

        databases = {str(d.uuid): d for d in db.session.query(Database).all()}

        for p, doc in iter_datasets(filter_substr):
            n += 1
            name = doc.get("table_name", p.stem)
            db_uuid = str(doc.get("database_uuid"))
            database = databases.get(db_uuid)
            if database is None:
                print(f"{name}: FAIL - database_uuid {db_uuid} не найден в метабазе")
                all_ok = False
                continue

            sql = doc["sql"].strip().rstrip(";")
            templated = "{{" in sql or "{%" in sql
            try:
                if templated:
                    # Jinja-датасет (ENABLE_TEMPLATE_PROCESSING): рендерим как
                    # Superset без фильтров дашборда. get_form_data() внутри
                    # макросов требует request-контекст.
                    from superset.jinja_context import get_template_processor

                    with app.test_request_context():
                        sql = get_template_processor(database=database).process_template(sql)
                wrapped = f"SELECT * FROM (\n{sql}\n) t LIMIT 3"
                with database.get_sqla_engine() as engine:
                    with engine.connect() as conn:
                        # отрендеренный SQL содержит даты вида '00:00:00' -
                        # text() принял бы ':00' за bind-параметр. exec_driver_sql
                        # отдаёт драйверу пустой кортеж параметров, и MySQLdb /
                        # psycopg2 форматируют '%' (LIKE '%тест%') - экранируем.
                        result = (
                            conn.exec_driver_sql(wrapped.replace("%", "%%"))
                            if templated
                            else conn.execute(text(wrapped))
                        )
                        cols = list(result.keys())
                        rows = result.fetchall()
                print(f"{name}: OK rows={len(rows)} cols={cols}")
            except Exception as e:  # noqa: BLE001
                print(f"{name}: FAIL {str(e)[:300]}")
                all_ok = False

    if n == 0:
        print("run_dataset_sql: нет датасетов для проверки"
              + (f" (фильтр '{filter_substr}')" if filter_substr else ""))
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
