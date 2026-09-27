"""Flujo completo de autenticación contra Atlas (base de pruebas), con el correo simulado."""

import random
from collections.abc import Iterator
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

import bcrypt
import httpx
import pytest
from bson import ObjectId
from fastapi.testclient import TestClient

from app.application import create_app
from app.config import Settings
from app.container import Container
from app.database import Collections, Mongo
from app.security import hash_token
from tests.fakes import FakeMailService
from tests.integration.conftest import correo_unico
from tests.support import MemoryLogger

pytestmark = pytest.mark.integration

PASSWORD = "Clave-Integracion-2026"


@dataclass
class Flujo:
    client: TestClient
    mongo: Mongo
    mail: FakeMailService
    correo: str
    cred_id: ObjectId
    usuario_id: ObjectId

    def call(
        self, method: str, path: str, body: dict[str, Any] | None = None, token: str | None = None
    ) -> httpx.Response:
        headers = {"Authorization": f"Bearer {token}"} if token else {}
        response: httpx.Response = self.client.request(
            method, f"/api/v1{path}", json=body, headers=headers
        )
        return response


@pytest.fixture
def flujo(it_settings: Settings, it_mongo: Mongo) -> Iterator[Flujo]:
    correo, cred_id, usuario_id = correo_unico(), ObjectId(), ObjectId()
    # Hash con prefijo $2y$, como lo crea el backend PHP (mismo algoritmo que $2b$).
    hash_php = bcrypt.hashpw(PASSWORD.encode(), bcrypt.gensalt(10)).decode().replace("$2b$", "$2y$")
    ahora = datetime.now(UTC)
    it_mongo.collection(Collections.CREDENCIALES).insert_one(
        {
            "_id": cred_id,
            "correo": correo,
            "password": hash_php,
            "usuario_id": usuario_id,
            "estado_cuenta": "activa",
            "token_recuperacion": None,
            "token_expiracion": None,
            "intentos_fallidos": 0,
            "fecha_creacion": ahora,
            "ultimo_acceso": None,
        }
    )
    it_mongo.collection(Collections.USUARIOS).insert_one(
        {
            "_id": usuario_id,
            "credencial_id": cred_id,
            "nombre": "Integración",
            "apellido": "SIIIS",
            "rol": "estudiante",
            "foto_perfil": None,
            "estado": True,
            "fecha_ingreso": ahora,
        }
    )

    mail = FakeMailService()
    container = Container.build(it_settings, logger=MemoryLogger(), mongo=it_mongo, mail=mail)
    ip = f"198.51.100.{random.randint(1, 254)}"  # noqa: S311
    client = TestClient(create_app(it_settings, container), client=(ip, 50000))
    try:
        yield Flujo(client, it_mongo, mail, correo, cred_id, usuario_id)
    finally:
        it_mongo.collection(Collections.SESIONES).delete_many({"usuario_id": usuario_id})
        it_mongo.collection(Collections.USUARIOS).delete_one({"_id": usuario_id})
        it_mongo.collection(Collections.CREDENCIALES).delete_one({"_id": cred_id})


def test_health(flujo: Flujo) -> None:
    assert flujo.call("GET", "/health").json() == {"status": "ok", "db": "ok"}


def test_login_me_logout_me(flujo: Flujo) -> None:
    login = flujo.call(
        "POST", "/auth/login", {"correo": flujo.correo.upper(), "password": PASSWORD}
    )
    assert login.status_code == 200, login.text
    token = login.json()["token"]

    sesion = flujo.mongo.collection(Collections.SESIONES).find_one({"usuario_id": flujo.usuario_id})
    assert sesion is not None
    assert sesion["activa"] is True
    assert len(sesion["token"]) == 64
    assert isinstance(sesion["fecha_expiracion"], datetime)
    assert (sesion["fecha_expiracion"] - sesion["fecha_inicio"]).total_seconds() == 28800

    # Un hash $2y$ vigente (coste 10) no se reescribe.
    credencial = flujo.mongo.collection(Collections.CREDENCIALES).find_one({"_id": flujo.cred_id})
    assert credencial is not None
    assert credencial["password"].startswith("$2y$10$")
    assert isinstance(credencial["ultimo_acceso"], datetime)

    me = flujo.call("GET", "/auth/me", token=token)
    assert me.status_code == 200
    assert me.json()["usuario"]["id"] == str(flujo.usuario_id)

    assert flujo.call("POST", "/auth/logout", token=token).status_code == 204
    assert flujo.call("GET", "/auth/me", token=token).status_code == 401


def test_forgot_y_reset_de_punta_a_punta(flujo: Flujo) -> None:
    login = flujo.call("POST", "/auth/login", {"correo": flujo.correo, "password": PASSWORD})
    token = login.json()["token"]

    assert flujo.call("POST", "/auth/forgot-password", {"correo": flujo.correo}).status_code == 200
    reset_token = flujo.mail.ultimo_token()

    credencial = flujo.mongo.collection(Collections.CREDENCIALES).find_one({"_id": flujo.cred_id})
    assert credencial is not None
    assert credencial["token_recuperacion"] == hash_token(reset_token)

    nueva = "Nueva-Clave-2026"
    reset = flujo.call("POST", "/auth/reset-password", {"token": reset_token, "password": nueva})
    assert reset.status_code == 200, reset.text

    assert flujo.call("GET", "/auth/me", token=token).status_code == 401
    viejo = {"correo": flujo.correo, "password": PASSWORD}
    assert flujo.call("POST", "/auth/login", viejo).status_code == 401
    assert (
        flujo.call("POST", "/auth/login", {"correo": flujo.correo, "password": nueva}).status_code
        == 200
    )
    otra = {"token": reset_token, "password": "Otra-Clave-2026"}
    assert flujo.call("POST", "/auth/reset-password", otra).status_code == 400
