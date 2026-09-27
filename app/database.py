"""Cliente único a MongoDB Atlas y nombres oficiales de las colecciones (spec 8.2).

Sin reglas de negocio. No crea ni modifica índices: ya existen en Atlas (creados por PHP).
"""

from datetime import UTC
from typing import Any

from pymongo import MongoClient
from pymongo.collection import Collection
from pymongo.database import Database
from pymongo.errors import PyMongoError

type Document = dict[str, Any]


class Collections:
    """Usar siempre estas constantes para evitar errores de tildes, eñes y mayúsculas."""

    CREDENCIALES = "Credenciales"
    USUARIOS = "Usuarios"
    DOCUMENTOS = "Documentos"
    MULTIMEDIA = "Multimedia"
    RESENAS = "Reseñas"
    INSCRIPCIONES = "Inscripciones"
    SESIONES = "Sesiones"
    MENSAJES_CONTACTO = "Mensajes_Contacto"


class Mongo:
    # Mismo tope que PHP para que una petición no quede colgada.
    SERVER_SELECTION_TIMEOUT_MS = 5000

    def __init__(self, uri: str, database_name: str) -> None:
        self._uri = uri
        self._database_name = database_name
        self._client: MongoClient[Document] | None = None

    @property
    def database_name(self) -> str:
        return self._database_name

    def client(self) -> MongoClient[Document]:
        if self._client is None:
            self._client = MongoClient(
                self._uri,
                serverSelectionTimeoutMS=self.SERVER_SELECTION_TIMEOUT_MS,
                tz_aware=True,
                tzinfo=UTC,
            )
        return self._client

    def database(self) -> Database[Document]:
        return self.client()[self._database_name]

    def collection(self, name: str) -> Collection[Document]:
        return self.database()[name]

    def ping(self) -> bool:
        """Comprueba la conexión con un comando ping. No lanza excepciones del driver."""
        try:
            self.database().command("ping")
        except PyMongoError:
            return False
        return True

    def close(self) -> None:
        if self._client is not None:
            self._client.close()
            self._client = None
