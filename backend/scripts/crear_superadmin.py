"""Crea el par Credenciales + Usuarios con rol superadministrador, en una transacción de Atlas.

Mismo comportamiento que `scripts/crear_superadmin.php` del backend PHP: pide los datos por
consola (la contraseña sin eco) y falla si el correo ya existe.

Uso (desde la raíz del proyecto):
    python -m scripts.crear_superadmin [--test]

`--test` usa MONGO_DB_NAME_TEST en lugar de MONGO_DB_NAME.
"""

import getpass
import sys
from datetime import UTC, datetime
from typing import Any, NoReturn

from bson import ObjectId
from pymongo.client_session import ClientSession
from pymongo.errors import DuplicateKeyError, PyMongoError

from app.config import ConfigError, load_settings
from app.database import Collections, Document, Mongo
from app.security import BCRYPT_MAX_BYTES, PasswordHasher
from app.validation import php_email_valido


def validar(correo: str, nombre: str, apellido: str, password: str, min_length: int) -> str | None:
    """Devuelve el mensaje de error (como el script PHP) o None si los datos son válidos."""
    if not php_email_valido(correo):
        return "correo no válido."
    if nombre == "" or apellido == "":
        return "nombre y apellido son obligatorios."
    if len(password) < min_length:
        return f"la contraseña debe tener al menos {min_length} caracteres."
    if len(password.encode("utf-8")) > BCRYPT_MAX_BYTES:
        return "la contraseña no puede superar 72 bytes (límite de bcrypt)."
    return None


def construir_documentos(
    credencial_id: ObjectId,
    usuario_id: ObjectId,
    correo: str,
    hashed: str,
    nombre: str,
    apellido: str,
    ahora: datetime,
) -> tuple[Document, Document]:
    """Mismos campos, en el mismo orden, que el script PHP."""
    credencial: Document = {
        "_id": credencial_id,
        "correo": correo,
        "password": hashed,
        "usuario_id": usuario_id,
        "estado_cuenta": "activa",
        "token_recuperacion": None,
        "token_expiracion": None,
        "intentos_fallidos": 0,
        "fecha_creacion": ahora,
        "ultimo_acceso": None,
    }
    usuario: Document = {
        "_id": usuario_id,
        "credencial_id": credencial_id,
        "nombre": nombre,
        "apellido": apellido,
        "rol": "superadministrador",
        "programa_academico": None,
        "semestre": None,
        "foto_perfil": None,
        "biografia": None,
        "telefono": None,
        "estado": True,
        "fecha_ingreso": ahora,
        "fecha_salida": None,
    }
    return credencial, usuario


def fallar(mensaje: str) -> NoReturn:
    print(f"Error: {mensaje}", file=sys.stderr)
    raise SystemExit(1)


def preguntar_oculto(etiqueta: str) -> str:
    # Entrada redirigida (tubería/archivo): no hay eco que ocultar.
    if not sys.stdin.isatty():
        print(etiqueta, end="", flush=True)
        linea = sys.stdin.readline().rstrip("\r\n")
        print()
        return linea
    return getpass.getpass(etiqueta)


def main(argv: list[str]) -> None:
    try:
        settings = load_settings()
    except ConfigError as exc:
        fallar(f"de configuración: {exc}")

    usar_test = "--test" in argv
    db_name = settings.mongo_db_name_test if usar_test else settings.mongo_db_name
    if usar_test and db_name == settings.mongo_db_name:
        fallar("MONGO_DB_NAME_TEST no puede ser igual a MONGO_DB_NAME.")
    mongo = Mongo(settings.mongo_uri, db_name)
    print(f"Base de datos: {db_name}")

    correo = input("Correo: ").strip().lower()
    nombre = input("Nombre: ").strip()
    apellido = input("Apellido: ").strip()
    password = preguntar_oculto("Contraseña: ")
    error = validar(correo, nombre, apellido, password, settings.password_min_length)
    if error:
        fallar(error)
    if preguntar_oculto("Repite la contraseña: ") != password:
        fallar("las contraseñas no coinciden.")

    credenciales = mongo.collection(Collections.CREDENCIALES)
    usuarios = mongo.collection(Collections.USUARIOS)
    if credenciales.count_documents({"correo": correo}, limit=1) > 0:
        fallar("ya existe una cuenta con ese correo.")

    credencial, usuario = construir_documentos(
        ObjectId(),
        ObjectId(),
        correo,
        PasswordHasher().hash(password),
        nombre,
        apellido,
        datetime.now(UTC),
    )

    def insertar(session: ClientSession) -> Any:
        credenciales.insert_one(credencial, session=session)
        usuarios.insert_one(usuario, session=session)

    try:
        with mongo.client().start_session() as session:
            session.with_transaction(insertar)
    except DuplicateKeyError:
        fallar("ya existe una cuenta con ese correo.")
    except PyMongoError as exc:
        fallar(f"no se pudo crear el superadministrador: {exc}")
    finally:
        mongo.close()

    print(f"Superadministrador creado. Usuarios._id = {usuario['_id']}")


if __name__ == "__main__":
    main(sys.argv[1:])
