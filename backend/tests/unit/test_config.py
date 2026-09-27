from pathlib import Path

import pytest

from app.config import ConfigError, Settings, load_settings

SECRETO_VALIDO = "x" * 32


@pytest.fixture(autouse=True)
def entorno_limpio(monkeypatch: pytest.MonkeyPatch) -> None:
    for campo in Settings.model_fields:
        monkeypatch.delenv(campo.upper(), raising=False)


def escribir_env(tmp_path: Path, contenido: str) -> str:
    ruta = tmp_path / ".env"
    ruta.write_text(contenido, encoding="utf-8")
    return str(ruta)


def test_carga_valores_y_defaults(tmp_path: Path) -> None:
    env = escribir_env(
        tmp_path,
        "MONGO_URI=mongodb://localhost\n"
        f"JWT_SECRET={SECRETO_VALIDO}\n"
        'MAIL_FROM_NAME="Semillero SIIIS"\n',
    )
    settings = load_settings(env)
    assert settings.mongo_uri == "mongodb://localhost"
    assert settings.mongo_db_name == "siiis"
    assert settings.jwt_ttl_seconds == 28800
    assert settings.home_max_items == 20
    assert settings.mail_from_name == "Semillero SIIIS"


def test_falta_mongo_uri(tmp_path: Path) -> None:
    env = escribir_env(tmp_path, f"MONGO_URI=\nJWT_SECRET={SECRETO_VALIDO}\n")
    with pytest.raises(ConfigError, match="MONGO_URI: falta la variable"):
        load_settings(env)


def test_falta_jwt_secret(tmp_path: Path) -> None:
    env = escribir_env(tmp_path, "MONGO_URI=mongodb://localhost\n")
    with pytest.raises(ConfigError, match="JWT_SECRET: falta la variable"):
        load_settings(env)


def test_jwt_secret_corto_no_muestra_el_valor(tmp_path: Path) -> None:
    secreto_corto = "secreto-corto-123"
    env = escribir_env(tmp_path, f"MONGO_URI=mongodb://localhost\nJWT_SECRET={secreto_corto}\n")
    with pytest.raises(ConfigError, match="al menos 32 caracteres") as exc_info:
        load_settings(env)
    assert secreto_corto not in str(exc_info.value)


def test_variables_de_entorno_tienen_prioridad(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    env = escribir_env(tmp_path, f"MONGO_URI=mongodb://archivo\nJWT_SECRET={SECRETO_VALIDO}\n")
    monkeypatch.setenv("MONGO_URI", "mongodb://entorno")
    assert load_settings(env).mongo_uri == "mongodb://entorno"


def test_reglas_de_php_trim_bool_y_defaults(tmp_path: Path) -> None:
    env = escribir_env(
        tmp_path,
        "MONGO_URI=  mongodb://localhost  \n"
        f"JWT_SECRET={SECRETO_VALIDO}\n"
        "APP_DEBUG=on\n"
        "MAIL_FROM_NAME=   \n"
        "HOME_MAX_ITEMS=\n",
    )
    settings = load_settings(env)
    assert settings.mongo_uri == "mongodb://localhost"
    assert settings.app_debug is True
    assert settings.app_env == "production"
    assert settings.mail_from_name == "Semillero SIIIS"
    assert settings.home_max_items == 20


def test_app_debug_con_texto_desconocido_es_false(tmp_path: Path) -> None:
    env = escribir_env(tmp_path, f"MONGO_URI=m\nJWT_SECRET={SECRETO_VALIDO}\nAPP_DEBUG=quizas\n")
    assert load_settings(env).app_debug is False


@pytest.mark.parametrize("valor", ["0", "-5", "+5", "5.0", "diez"])
def test_enteros_deben_ser_positivos(tmp_path: Path, valor: str) -> None:
    env = escribir_env(
        tmp_path, f"MONGO_URI=m\nJWT_SECRET={SECRETO_VALIDO}\nRATE_LIMIT_LOGIN_MAX={valor}\n"
    )
    with pytest.raises(ConfigError, match="RATE_LIMIT_LOGIN_MAX: debe ser un entero positivo"):
        load_settings(env)


def test_secreto_con_solo_espacios_cuenta_como_ausente(tmp_path: Path) -> None:
    env = escribir_env(tmp_path, "MONGO_URI=m\nJWT_SECRET=      \n")
    with pytest.raises(ConfigError, match="JWT_SECRET: falta la variable"):
        load_settings(env)
