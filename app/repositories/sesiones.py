"""Acceso a la colección Sesiones (spec 8.2 y 10.13.6).

`token` guarda el hash SHA-256 del `jti`, nunca el JWT.
"""

from datetime import datetime

from bson import ObjectId

from app.database import Collections, Document, Mongo


class SesionesRepository:
    def __init__(self, mongo: Mongo) -> None:
        self._mongo = mongo

    def crear(
        self,
        usuario_id: ObjectId,
        token_hash: str,
        inicio: datetime,
        expiracion: datetime,
        ip: str,
    ) -> None:
        self._mongo.collection(Collections.SESIONES).insert_one(
            {
                "usuario_id": usuario_id,
                "token": token_hash,
                "fecha_inicio": inicio,
                "fecha_expiracion": expiracion,
                "ip_acceso": ip,
                "activa": True,
            }
        )

    def find_activa(self, token_hash: str, ahora: datetime) -> Document | None:
        """Sesión activa y no vencida."""
        return self._mongo.collection(Collections.SESIONES).find_one(
            {"token": token_hash, "activa": True, "fecha_expiracion": {"$gt": ahora}}
        )

    def desactivar(self, token_hash: str) -> None:
        self._mongo.collection(Collections.SESIONES).update_one(
            {"token": token_hash}, {"$set": {"activa": False}}
        )

    def desactivar_todas(self, usuario_id: ObjectId) -> None:
        self._mongo.collection(Collections.SESIONES).update_many(
            {"usuario_id": usuario_id, "activa": True}, {"$set": {"activa": False}}
        )
