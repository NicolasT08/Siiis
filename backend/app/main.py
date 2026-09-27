"""Punto de entrada: `uvicorn app.main:app --reload --port 8001`.

Falla al arrancar, con un mensaje claro, si la configuración del .env no es válida.
"""

import sys

from app.application import create_app
from app.config import ConfigError, get_settings

try:
    _settings = get_settings()
except ConfigError as exc:
    print(f"[SIIIS] Error al arrancar la API: {exc}", file=sys.stderr)
    raise SystemExit(1) from None

app = create_app(_settings)
