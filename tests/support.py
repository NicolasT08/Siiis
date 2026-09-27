"""Utilidades compartidas por las pruebas: configuración, reloj fijo y logger en memoria."""

from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Any

from app.config import Settings
from app.logger import Logger

JWT_SECRET = "clave-de-pruebas-con-mas-de-32-caracteres!"
MONGO_URI = "mongodb://localhost:27017/?serverSelectionTimeoutMS=100"


def make_settings(**overrides: Any) -> Settings:
    """Settings de prueba que no leen el .env real."""
    valores: dict[str, Any] = {
        "mongo_uri": MONGO_URI,
        "jwt_secret": JWT_SECRET,
        "app_env": "testing",
        "app_debug": False,
    }
    valores.update(overrides)
    return Settings(_env_file=None, **valores)


class FixedClock:
    def __init__(self, now: datetime | None = None) -> None:
        self._now = now or datetime(2026, 9, 23, 15, 0, 0, tzinfo=UTC)

    def now(self) -> datetime:
        return self._now

    def advance(self, seconds: int) -> None:
        self._now += timedelta(seconds=seconds)


class MemoryLogger(Logger):
    def __init__(self) -> None:
        super().__init__(Path("."))
        self.records: list[tuple[str, str, dict[str, Any]]] = []

    def _write(self, level: str, message: str, context: dict[str, Any] | None) -> None:
        self.records.append((level, message, context or {}))
