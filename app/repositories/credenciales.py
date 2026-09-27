"""Acceso a la colección Credenciales (spec 8.2). Los parámetros llegan ya validados y tipados."""

from datetime import datetime

from bson import ObjectId
from pymongo import ReturnDocument

from app.database import Collections, Document, Mongo


class CredencialesRepository:
    def __init__(self, mongo: Mongo) -> None:
        self._mongo = mongo

    def find_by_correo(self, correo: str) -> Document | None:
        return self._mongo.collection(Collections.CREDENCIALES).find_one({"correo": correo})

    def find_by_token_recuperacion(self, token_hash: str) -> Document | None:
        return self._mongo.collection(Collections.CREDENCIALES).find_one(
            {"token_recuperacion": token_hash}
        )

    def registrar_intento_fallido(self, credencial_id: ObjectId) -> int:
        """Incrementa intentos_fallidos y devuelve el nuevo valor."""
        doc = self._mongo.collection(Collections.CREDENCIALES).find_one_and_update(
            {"_id": credencial_id},
            {"$inc": {"intentos_fallidos": 1}},
            projection={"intentos_fallidos": 1},
            return_document=ReturnDocument.AFTER,
        )
        intentos = doc.get("intentos_fallidos") if doc else None
        return intentos if isinstance(intentos, int) and not isinstance(intentos, bool) else 0

    def registrar_acceso_exitoso(
        self, credencial_id: ObjectId, fecha: datetime, nuevo_hash: str | None = None
    ) -> None:
        """Login exitoso: reinicia intentos_fallidos, actualiza ultimo_acceso y, si hace falta,
        el hash."""
        cambios: Document = {"intentos_fallidos": 0, "ultimo_acceso": fecha}
        if nuevo_hash is not None:
            cambios["password"] = nuevo_hash
        self._mongo.collection(Collections.CREDENCIALES).update_one(
            {"_id": credencial_id}, {"$set": cambios}
        )

    def guardar_token_recuperacion(
        self, credencial_id: ObjectId, token_hash: str, expiracion: datetime
    ) -> None:
        self._mongo.collection(Collections.CREDENCIALES).update_one(
            {"_id": credencial_id},
            {"$set": {"token_recuperacion": token_hash, "token_expiracion": expiracion}},
        )

    def cambiar_password(self, credencial_id: ObjectId, hashed: str) -> None:
        """Guarda la nueva contraseña e invalida el token de recuperación (un solo uso)."""
        self._mongo.collection(Collections.CREDENCIALES).update_one(
            {"_id": credencial_id},
            {"$set": {"password": hashed, "token_recuperacion": None, "token_expiracion": None}},
        )
