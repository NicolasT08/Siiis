"""Configuración de la aplicación leída del `.env` (mismos nombres que el backend PHP).

Mismas reglas que `Config/Settings.php`: los valores se recortan (trim), un valor vacío usa el
valor por defecto, los enteros deben ser positivos y la app no arranca si falta `MONGO_URI` o
`JWT_SECRET`, o si el secreto tiene menos de 32 bytes.
"""

from functools import lru_cache
from typing import Any

from pydantic import ValidationError, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

JWT_SECRET_MIN_LENGTH = 32

_INT_FIELDS = (
    "jwt_ttl_seconds",
    "max_login_attempts",
    "password_min_length",
    "password_reset_ttl_minutes",
    "rate_limit_login_max",
    "rate_limit_login_window_seconds",
    "rate_limit_forgot_max",
    "rate_limit_forgot_window_seconds",
    "home_max_items",
    "mail_port",
)


class ConfigError(RuntimeError):
    """Configuración inválida: la aplicación no debe arrancar."""


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
        # Nunca incluir valores del .env en los mensajes de error.
        hide_input_in_errors=True,
    )

    app_env: str = "production"
    app_debug: bool = False
    app_url: str = "http://localhost:8001"
    frontend_origin: str = "http://localhost:5173"

    mongo_uri: str
    mongo_db_name: str = "siiis"
    mongo_db_name_test: str = "siiis_test"

    jwt_secret: str
    jwt_ttl_seconds: int = 28800
    max_login_attempts: int = 5

    password_min_length: int = 8
    password_reset_ttl_minutes: int = 30
    password_reset_url: str = "http://localhost:5173/restablecer"  # noqa: S105

    rate_limit_login_max: int = 10
    rate_limit_login_window_seconds: int = 900
    rate_limit_forgot_max: int = 5
    rate_limit_forgot_window_seconds: int = 3600

    home_max_items: int = 20

    mail_host: str = "smtp.gmail.com"
    mail_port: int = 587
    mail_username: str = ""
    mail_password: str = ""
    mail_from_address: str = ""
    mail_from_name: str = "Semillero SIIIS"

    cloudinary_cloud_name: str = ""
    cloudinary_api_key: str = ""
    cloudinary_api_secret: str = ""

    @model_validator(mode="before")
    @classmethod
    def _trim_y_vacios(cls, data: Any) -> Any:
        """trim() a cada valor; un valor vacío cuenta como ausente (usa el valor por defecto)."""
        if not isinstance(data, dict):
            return data
        limpio: dict[str, Any] = {}
        for clave, valor in data.items():
            if isinstance(valor, str):
                valor = valor.strip()
                if valor == "":
                    continue
            limpio[clave] = valor
        return limpio

    @field_validator("app_debug", mode="before")
    @classmethod
    def _bool_php(cls, value: Any) -> Any:
        # PHP: solo "1", "true", "yes" u "on" son verdaderos; cualquier otro texto es falso.
        if isinstance(value, str):
            return value.lower() in {"1", "true", "yes", "on"}
        return value

    @field_validator(*_INT_FIELDS, mode="before")
    @classmethod
    def _entero_positivo(cls, value: Any) -> Any:
        if isinstance(value, str) and not (value.isascii() and value.isdigit()):
            raise ValueError("debe ser un entero positivo")
        if isinstance(value, str):
            value = int(value)
        if isinstance(value, int) and value <= 0:
            raise ValueError("debe ser un entero positivo")
        return value

    @field_validator("jwt_secret")
    @classmethod
    def _longitud_secreto(cls, value: str) -> str:
        if len(value.encode("utf-8")) < JWT_SECRET_MIN_LENGTH:
            raise ValueError(f"debe tener al menos {JWT_SECRET_MIN_LENGTH} caracteres")
        return value

    @property
    def is_development(self) -> bool:
        return self.app_env == "development"


def load_settings(env_file: str | None = ".env") -> Settings:
    """Carga la configuración o lanza `ConfigError` con un mensaje claro y sin valores."""
    try:
        return Settings(_env_file=env_file)
    except ValidationError as exc:
        raise ConfigError(_describir(exc)) from None


def _describir(exc: ValidationError) -> str:
    problemas = []
    for err in exc.errors():
        campo = str(err["loc"][0]).upper() if err["loc"] else "?"
        if err["type"] == "missing":
            problemas.append(f"{campo}: falta la variable o está vacía")
        else:
            problemas.append(f"{campo}: {err['msg'].removeprefix('Value error, ')}")
    detalle = "\n  - ".join(problemas)
    return f"Configuración inválida en .env:\n  - {detalle}"


@lru_cache
def get_settings() -> Settings:
    return load_settings()
