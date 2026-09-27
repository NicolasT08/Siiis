"""Pruebas contra MongoDB Atlas en la base MONGO_DB_NAME_TEST.

Se saltan si no hay MONGO_URI o conexión, y nunca se ejecutan contra la base principal ("siiis").
Cada prueba borra los datos que crea.
"""

import secrets
from collections.abc import Iterator

import pytest

from app.config import ConfigError, Settings, load_settings
from app.database import Mongo

BASE_PRINCIPAL = "siiis"


@pytest.fixture(scope="session")
def it_settings() -> Settings:
    try:
        base = load_settings()
    except ConfigError as exc:
        pytest.skip(f"Sin configuración válida (MONGO_URI/JWT_SECRET): {exc}")

    test_db = base.mongo_db_name_test
    if test_db in ("", BASE_PRINCIPAL, base.mongo_db_name):
        pytest.skip(
            f'MONGO_DB_NAME_TEST ("{test_db}") debe existir y ser distinta de la base principal.'
        )
    return base.model_copy(
        update={"mongo_db_name": test_db, "app_env": "testing", "app_debug": False}
    )


@pytest.fixture(scope="session")
def it_mongo(it_settings: Settings) -> Iterator[Mongo]:
    mongo = Mongo(it_settings.mongo_uri, it_settings.mongo_db_name)
    if not mongo.ping():
        pytest.skip("No hay conexión con MongoDB Atlas.")
    assert mongo.database_name != BASE_PRINCIPAL
    yield mongo
    mongo.close()


def correo_unico() -> str:
    return f"it-{secrets.token_hex(6)}@example.test"
