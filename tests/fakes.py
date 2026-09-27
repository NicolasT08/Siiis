"""Dobles en memoria de Mongo y de los repositorios (equivalentes a tests/Support de PHP)."""

from datetime import UTC, datetime
from typing import Any

import bcrypt
from bson import ObjectId

from app.database import Document, Mongo
from app.repositories.credenciales import CredencialesRepository
from app.repositories.multimedia import MultimediaRepository
from app.repositories.sesiones import SesionesRepository
from app.repositories.usuarios import UsuariosRepository


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


class InMemoryDatabase:
    """ "Base de datos" en memoria compartida por los repositorios de prueba."""

    def __init__(self) -> None:
        self.credenciales: dict[str, Document] = {}
        self.usuarios: dict[str, Document] = {}
        self.sesiones: dict[str, Document] = {}

    def crear_cuenta(
        self,
        correo: str,
        password: str,
        credencial: Document | None = None,
        usuario: Document | None = None,
    ) -> tuple[ObjectId, ObjectId]:
        """Crea un par Credenciales + Usuarios y devuelve (credencial_id, usuario_id)."""
        credencial_id, usuario_id = ObjectId(), ObjectId()
        self.credenciales[str(credencial_id)] = {
            "_id": credencial_id,
            "correo": correo,
            # Coste 4 para que las pruebas sean rápidas (el login lo rehashea a coste 10).
            "password": bcrypt.hashpw(password.encode(), bcrypt.gensalt(4)).decode(),
            "usuario_id": usuario_id,
            "estado_cuenta": "activa",
            "token_recuperacion": None,
            "token_expiracion": None,
            "intentos_fallidos": 0,
            "fecha_creacion": datetime.now(UTC),
            "ultimo_acceso": None,
            **(credencial or {}),
        }
        self.usuarios[str(usuario_id)] = {
            "_id": usuario_id,
            "credencial_id": credencial_id,
            "nombre": "Ana",
            "apellido": "Pérez",
            "rol": "superadministrador",
            "foto_perfil": {
                "url": "https://res.cloudinary.com/demo/image/upload/ana.jpg",
                "public_id": "ana",
            },
            "estado": True,
            **(usuario or {}),
        }
        return credencial_id, usuario_id


class InMemoryCredencialesRepository(CredencialesRepository):
    def __init__(self, db: InMemoryDatabase) -> None:
        super().__init__(StubMongo())
        self._db = db
        self.consultas: list[Any] = []  # filtros recibidos, para comprobar que no llegan dicts

    def find_by_correo(self, correo: str) -> Document | None:
        self.consultas.append(correo)
        return next((d for d in self._db.credenciales.values() if d["correo"] == correo), None)

    def find_by_token_recuperacion(self, token_hash: str) -> Document | None:
        return next(
            (
                d
                for d in self._db.credenciales.values()
                if d.get("token_recuperacion") == token_hash
            ),
            None,
        )

    def registrar_intento_fallido(self, credencial_id: ObjectId) -> int:
        doc = self._db.credenciales[str(credencial_id)]
        doc["intentos_fallidos"] = int(doc["intentos_fallidos"]) + 1
        return int(doc["intentos_fallidos"])

    def registrar_acceso_exitoso(
        self, credencial_id: ObjectId, fecha: datetime, nuevo_hash: str | None = None
    ) -> None:
        doc = self._db.credenciales[str(credencial_id)]
        doc["intentos_fallidos"] = 0
        doc["ultimo_acceso"] = fecha
        if nuevo_hash is not None:
            doc["password"] = nuevo_hash

    def guardar_token_recuperacion(
        self, credencial_id: ObjectId, token_hash: str, expiracion: datetime
    ) -> None:
        doc = self._db.credenciales[str(credencial_id)]
        doc["token_recuperacion"] = token_hash
        doc["token_expiracion"] = expiracion

    def cambiar_password(self, credencial_id: ObjectId, hashed: str) -> None:
        doc = self._db.credenciales[str(credencial_id)]
        doc["password"] = hashed
        doc["token_recuperacion"] = None
        doc["token_expiracion"] = None


class InMemoryUsuariosRepository(UsuariosRepository):
    def __init__(self, db: InMemoryDatabase) -> None:
        super().__init__(StubMongo())
        self._db = db

    def find_by_id(self, usuario_id: ObjectId) -> Document | None:
        return self._db.usuarios.get(str(usuario_id))

    def find_by_credencial_id(self, credencial_id: ObjectId) -> Document | None:
        return next(
            (d for d in self._db.usuarios.values() if d["credencial_id"] == credencial_id), None
        )


class InMemorySesionesRepository(SesionesRepository):
    def __init__(self, db: InMemoryDatabase) -> None:
        super().__init__(StubMongo())
        self._db = db

    def crear(
        self,
        usuario_id: ObjectId,
        token_hash: str,
        inicio: datetime,
        expiracion: datetime,
        ip: str,
    ) -> None:
        sesion_id = ObjectId()
        self._db.sesiones[str(sesion_id)] = {
            "_id": sesion_id,
            "usuario_id": usuario_id,
            "token": token_hash,
            "fecha_inicio": inicio,
            "fecha_expiracion": expiracion,
            "ip_acceso": ip,
            "activa": True,
        }

    def find_activa(self, token_hash: str, ahora: datetime) -> Document | None:
        return next(
            (
                d
                for d in self._db.sesiones.values()
                if d["token"] == token_hash
                and d["activa"] is True
                and d["fecha_expiracion"] > ahora
            ),
            None,
        )

    def desactivar(self, token_hash: str) -> None:
        for doc in self._db.sesiones.values():
            if doc["token"] == token_hash:
                doc["activa"] = False

    def desactivar_todas(self, usuario_id: ObjectId) -> None:
        for doc in self._db.sesiones.values():
            if doc["usuario_id"] == usuario_id:
                doc["activa"] = False
