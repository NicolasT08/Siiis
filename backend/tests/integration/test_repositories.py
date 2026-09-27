import secrets
from collections.abc import Iterator
from datetime import UTC, datetime, timedelta

import pytest
from bson import ObjectId
from pymongo.errors import DuplicateKeyError

from app.database import Collections, Document, Mongo
from app.repositories.credenciales import CredencialesRepository
from app.repositories.multimedia import MultimediaRepository
from app.repositories.sesiones import SesionesRepository
from app.repositories.usuarios import UsuariosRepository
from app.security import hash_token
from tests.integration.conftest import correo_unico

pytestmark = pytest.mark.integration


class Creados:
    def __init__(self, mongo: Mongo) -> None:
        self.mongo = mongo
        self.docs: list[tuple[str, ObjectId]] = []

    def insertar(self, coleccion: str, doc: Document, oid: ObjectId | None = None) -> ObjectId:
        oid = oid or ObjectId()
        self.mongo.collection(coleccion).insert_one({"_id": oid, **doc})
        self.docs.append((coleccion, oid))
        return oid


@pytest.fixture
def creados(it_mongo: Mongo) -> Iterator[Creados]:
    registro = Creados(it_mongo)
    yield registro
    for coleccion, oid in registro.docs:
        it_mongo.collection(coleccion).delete_one({"_id": oid})


def test_credenciales_y_usuarios(it_mongo: Mongo, creados: Creados) -> None:
    correo, usuario_id = correo_unico(), ObjectId()
    cred_id = creados.insertar(
        Collections.CREDENCIALES,
        {
            "correo": correo,
            "password": "hash",
            "usuario_id": usuario_id,
            "estado_cuenta": "activa",
            "intentos_fallidos": 0,
        },
    )
    creados.insertar(
        Collections.USUARIOS,
        {
            "credencial_id": cred_id,
            "nombre": "IT",
            "apellido": "Test",
            "rol": "estudiante",
            "estado": True,
            "biografia": "no se proyecta",
        },
        usuario_id,
    )
    credenciales, usuarios = CredencialesRepository(it_mongo), UsuariosRepository(it_mongo)

    encontrado = credenciales.find_by_correo(correo)
    assert encontrado is not None and encontrado["_id"] == cred_id
    assert credenciales.find_by_correo("no-" + correo) is None
    assert credenciales.registrar_intento_fallido(cred_id) == 1
    assert credenciales.registrar_intento_fallido(cred_id) == 2

    credenciales.registrar_acceso_exitoso(cred_id, datetime.now(UTC), "nuevo-hash")
    doc = credenciales.find_by_correo(correo)
    assert doc is not None
    assert doc["intentos_fallidos"] == 0
    assert doc["password"] == "nuevo-hash"
    assert isinstance(doc["ultimo_acceso"], datetime)

    expira = datetime.now(UTC) + timedelta(minutes=30)
    credenciales.guardar_token_recuperacion(cred_id, "hash-token-" + correo, expira)
    por_token = credenciales.find_by_token_recuperacion("hash-token-" + correo)
    assert por_token is not None and por_token["_id"] == cred_id
    credenciales.cambiar_password(cred_id, "otro-hash")
    assert credenciales.find_by_token_recuperacion("hash-token-" + correo) is None

    usuario = usuarios.find_by_credencial_id(cred_id)
    assert usuario is not None
    assert usuario["nombre"] == "IT"
    assert "biografia" not in usuario
    por_id = usuarios.find_by_id(usuario_id)
    assert por_id is not None and por_id["_id"] == usuario_id


def test_correo_unico_por_indice_existente(creados: Creados) -> None:
    """Comprueba el índice único creado por el backend PHP (Python no crea índices)."""
    correo = correo_unico()
    creados.insertar(Collections.CREDENCIALES, {"correo": correo})

    with pytest.raises(DuplicateKeyError):
        creados.insertar(Collections.CREDENCIALES, {"correo": correo})


def test_sesiones(it_mongo: Mongo) -> None:
    repo = SesionesRepository(it_mongo)
    usuario_id, ahora = ObjectId(), datetime.now(UTC)
    hash_a, hash_b = hash_token(secrets.token_hex(8)), hash_token(secrets.token_hex(8))

    repo.crear(usuario_id, hash_a, ahora, ahora + timedelta(hours=1), "127.0.0.1")
    repo.crear(usuario_id, hash_b, ahora, ahora + timedelta(hours=1), "127.0.0.1")
    try:
        assert repo.find_activa(hash_a, ahora) is not None
        assert repo.find_activa(hash_a, ahora + timedelta(hours=2)) is None, "sesión vencida"

        repo.desactivar(hash_a)
        assert repo.find_activa(hash_a, ahora) is None
        assert repo.find_activa(hash_b, ahora) is not None

        repo.desactivar_todas(usuario_id)
        assert repo.find_activa(hash_b, ahora) is None

        guardada = it_mongo.collection(Collections.SESIONES).find_one({"token": hash_a})
        assert guardada is not None
        assert list(guardada) == [
            "_id",
            "usuario_id",
            "token",
            "fecha_inicio",
            "fecha_expiracion",
            "ip_acceso",
            "activa",
        ]
    finally:
        it_mongo.collection(Collections.SESIONES).delete_many({"usuario_id": usuario_id})


def test_multimedia_proyeccion_y_orden(it_mongo: Mongo, creados: Creados) -> None:
    marca = secrets.token_hex(4)
    for orden in (3, 1, 2):
        creados.insertar(
            Collections.MULTIMEDIA,
            {
                "tipo": "imagen",
                "url": f"https://example.test/{marca}/{orden}.jpg",
                "public_id": f"secreto-{marca}",
                "seccion": "galeria",
                "descripcion": "IT",
                "orden": orden,
                "fecha_subida": datetime.now(UTC),
            },
        )

    items = MultimediaRepository(it_mongo).find_by_seccion("galeria", 100)

    ordenes = [item["orden"] for item in items]
    assert ordenes == sorted(ordenes)
    for item in items:
        assert list(item) == ["_id", "tipo", "url", "descripcion", "orden"]
    assert len(MultimediaRepository(it_mongo).find_by_seccion("galeria", 1)) == 1
