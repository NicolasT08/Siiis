"""Dobles en memoria de Mongo y de los repositorios (equivalentes a tests/Support de PHP)."""

from typing import Any

from app.database import Document, Mongo
from app.repositories.multimedia import MultimediaRepository


class StubMongo(Mongo):
    """Mongo que nunca se conecta: `ping` devuelve un valor fijo o lanza una excepción."""

    def __init__(self, ping: bool | Exception = True) -> None:
        super().__init__("mongodb://127.0.0.1:1", "siiis_unit")
        self._ping = ping

    def ping(self) -> bool:
        if isinstance(self._ping, Exception):
            raise self._ping
        return self._ping


class InMemoryMultimediaRepository(MultimediaRepository):
    """Imita find con filtro, proyección, orden y límite."""

    def __init__(self, docs: list[Document]) -> None:
        super().__init__(StubMongo())
        self._docs = docs
        self.llamadas: list[tuple[str, int]] = []

    def find_by_seccion(self, seccion: str, limite: int) -> list[Document]:
        self.llamadas.append((seccion, limite))
        items = sorted(
            (d for d in self._docs if d["seccion"] == seccion),
            key=lambda d: (d["orden"], str(d["_id"])),
        )
        campos = ("_id", "tipo", "url", "descripcion", "orden")
        return [{k: v for k, v in d.items() if k in campos} for d in items[:limite]]


def sin_uso(*_: Any) -> None:
    """Marcador para dobles que no deben llamarse."""
    raise AssertionError("no debería llamarse")
