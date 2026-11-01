import os

SECRET_KEY = os.environ["SUPERSET_SECRET_KEY"]
SQLALCHEMY_DATABASE_URI = os.environ["SUPERSET_DATABASE_URI"]

ROW_LIMIT = 5000
SQL_MAX_ROW = 100000

# Работаем за nginx reverse proxy
ENABLE_PROXY_FIX = True

# Русская локаль по умолчанию
BABEL_DEFAULT_LOCALE = "ru"
LANGUAGES = {
    "ru": {"flag": "ru", "name": "Russian"},
    "en": {"flag": "us", "name": "English"},
}

FEATURE_FLAGS = {
    "ALERT_REPORTS": False,
    # Jinja в SQL датасетов: ABC «Операционных отчётов» пересчитывается под период (20.10.2026)
    "ENABLE_TEMPLATE_PROCESSING": True,
}

# Русский формат чисел во всех чартах: 660 402,5 (неразрывный пробел между
# тысячами, запятая в дробной части) - 20.10.2026
D3_FORMAT = {
    "decimal": ",",
    "thousands": " ",
    "grouping": [3],
    "currency": ["", " ₽"],
}

# Палитра «Операционных отчётов» (ТЗ v2.1, п. 3.8) - 21.10.2026
EXTRA_CATEGORICAL_COLOR_SCHEMES = [
    {
        "id": "lastochkaOps",
        "description": "Палитра операционных отчётов: первый цвет - основная серия",
        "label": "Ласточка · операционные отчёты",
        "isDefault": False,
        "colors": ["#1FA8C9", "#454E7C", "#5AC189", "#FF9754", "#E94359", "#A2C7D3"],
    }
]
