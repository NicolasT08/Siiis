"""Reloj inyectable para poder probar expiraciones sin esperar."""

from datetime import UTC, datetime
from typing import Protocol


class Clock(Protocol):
    def now(self) -> datetime:
        """Fecha y hora actual en UTC (con zona horaria)."""
        ...


class SystemClock:
    def now(self) -> datetime:
        return datetime.now(UTC)
