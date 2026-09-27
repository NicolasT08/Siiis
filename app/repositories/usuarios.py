"""Acceso a la colección Usuarios (spec 8.2)."""

from bson import ObjectId

from app.database import Collections, Document, Mongo

# Campos necesarios para la sesión y el perfil resumido.
PROJECTION_SESION = {
    "credencial_id": 1,
    "nombre": 1,
    "apellido": 1,
    "rol": 1,
    "foto_perfil": 1,
    "estado": 1,
}


class UsuariosRepository:
    def __init__(self, mongo: Mongo) -> None:
        self._mongo = mongo

    def find_by_id(self, usuario_id: ObjectId) -> Document | None:
        return self._mongo.collection(Collections.USUARIOS).find_one(
            {"_id": usuario_id}, PROJECTION_SESION
        )

    def find_by_credencial_id(self, credencial_id: ObjectId) -> Document | None:
        return self._mongo.collection(Collections.USUARIOS).find_one(
            {"credencial_id": credencial_id}, PROJECTION_SESION
        )
