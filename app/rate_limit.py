"""Rate limiting de ventana fija por (acción, clave), en memoria del proceso (DEC-P05).

Mismo algoritmo que `Security/RateLimiter` de PHP, que guarda los contadores en archivos. La
clave (normalmente la IP) no se guarda en claro: se usa su hash, como en PHP.
"""

import hashlib
import math
import threading

from app.clock import Clock

# Por encima de este número de contadores se eliminan los que tienen la ventana vencida.
_MAX_ENTRADAS_ANTES_DE_PURGAR = 10_000


class RateLimiter:
    def __init__(self, clock: Clock) -> None:
        self._clock = clock
        self._lock = threading.Lock()
        # hash → (inicio de la ventana, contador, duración de la ventana)
        self._contadores: dict[str, tuple[int, int, int]] = {}

    def hit(self, action: str, key: str, max_hits: int, window_seconds: int) -> int:
        """Registra un intento. Devuelve 0 si está permitido, o los segundos que faltan para
        reintentar si se superó el límite."""
        now = math.floor(self._clock.now().timestamp())
        clave = hashlib.sha256(f"{action}|{key}".encode()).hexdigest()

        with self._lock:
            inicio, contador, _ = self._contadores.get(clave, (now, 0, window_seconds))
            if now - inicio >= window_seconds:
                inicio, contador = now, 0

            if contador >= max_hits:
                return max(1, inicio + window_seconds - now)

            self._contadores[clave] = (inicio, contador + 1, window_seconds)
            if len(self._contadores) > _MAX_ENTRADAS_ANTES_DE_PURGAR:
                self._purgar(now)
            return 0

    def _purgar(self, now: int) -> None:
        vencidas = [c for c, (ini, _, ventana) in self._contadores.items() if now - ini >= ventana]
        for clave in vencidas:
            del self._contadores[clave]
