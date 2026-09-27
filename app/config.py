"""Configuración de la aplicación leída del `.env` (mismos nombres que el backend PHP)."""

from functools import lru_cache

from pydantic import Field, ValidationError, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

JWT_SECRET_MIN_LENGTH = 32


class ConfigError(RuntimeError):
    """Configuración inválida: la aplicación no debe arrancar."""


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
        # Una variable vacía (`MONGO_URI=`) cuenta como ausente.
        env_ignore_empty=True,
        # Nunca incluir valores del .env en los mensajes de error.
        hide_input_in_errors=True,
    )

    app_env: str = "development"
    app_debug: bool = False
    app_url: str = "http://localhost:8001"
    frontend_origin: str = "http://localhost:5173"

    mongo_uri: str
    mongo_db_name: str = "siiis"
    mongo_db_name_test: str = "siiis_test"

    jwt_secret: str
    jwt_ttl_seconds: int = Field(default=28800, gt=0)
    max_login_attempts: int = Field(default=5, gt=0)

    password_min_length: int = Field(default=8, gt=0)
    password_reset_ttl_minutes: int = Field(default=30, gt=0)
    password_reset_url: str = "http://localhost:5173/restablecer"  # noqa: S105

    rate_limit_login_max: int = Field(default=10, gt=0)
    rate_limit_login_window_seconds: int = Field(default=900, gt=0)
    rate_limit_forgot_max: int = Field(default=5, gt=0)
    rate_limit_forgot_window_seconds: int = Field(default=3600, gt=0)

    home_max_items: int = Field(default=20, gt=0)

    mail_host: str = "smtp.gmail.com"
    mail_port: int = 587
    mail_username: str = ""
    mail_password: str = ""
    mail_from_address: str = ""
    mail_from_name: str = "Semillero SIIIS"

    cloudinary_cloud_name: str = ""
    cloudinary_api_key: str = ""
    cloudinary_api_secret: str = ""

    @field_validator("mongo_uri", "jwt_secret")
    @classmethod
    def _no_vacio(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("no puede estar vacía")
        return value

    @field_validator("jwt_secret")
    @classmethod
    def _longitud_secreto(cls, value: str) -> str:
        if len(value) < JWT_SECRET_MIN_LENGTH:
            raise ValueError(f"debe tener al menos {JWT_SECRET_MIN_LENGTH} caracteres")
        return value


def load_settings(env_file: str | None = ".env") -> Settings:
    """Carga la configuración o lanza `ConfigError` con un mensaje claro y sin valores."""
    try:
        return Settings(_env_file=env_file)
    except ValidationError as exc:
        problemas = []
        for err in exc.errors():
            campo = str(err["loc"][0]).upper() if err["loc"] else "?"
            if err["type"] == "missing":
                problemas.append(f"{campo}: falta la variable o está vacía")
            else:
                problemas.append(f"{campo}: {err['msg'].removeprefix('Value error, ')}")
        detalle = "\n  - ".join(problemas)
        raise ConfigError(f"Configuración inválida en .env:\n  - {detalle}") from None


@lru_cache
def get_settings() -> Settings:
    return load_settings()
