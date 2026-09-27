"""Logger mínimo a archivo: storage/logs/app-YYYY-MM-DD.log (mismo formato que PHP).

Nunca registrar contraseñas, tokens ni contenido sensible.
"""

import json
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any


class Logger:
    def __init__(self, log_directory: Path) -> None:
        self._dir = log_directory

    def error(self, message: str, context: dict[str, Any] | None = None) -> None:
        self._write("ERROR", message, context)

    def warning(self, message: str, context: dict[str, Any] | None = None) -> None:
        self._write("WARNING", message, context)

    def info(self, message: str, context: dict[str, Any] | None = None) -> None:
        self._write("INFO", message, context)

    def _write(self, level: str, message: str, context: dict[str, Any] | None) -> None:
        now = datetime.now(UTC)
        line = f"[{now.isoformat(timespec='seconds')}] {level}: {message}"
        if context:
            line += " " + json.dumps(context, ensure_ascii=False, default=str)

        try:
            self._dir.mkdir(parents=True, exist_ok=True)
            with (self._dir / f"app-{now:%Y-%m-%d}.log").open("a", encoding="utf-8") as fh:
                fh.write(line + "\n")
        except OSError:
            print(line, file=sys.stderr)
