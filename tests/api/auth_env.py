"""Entorno de pruebas de auth: repositorios en memoria, reloj fijo y logger en memoria."""

from typing import Any

import httpx
from fastapi.testclient import TestClient

from app.application import create_app
from app.container import Container
from tests.fakes import (
    InMemoryCredencialesRepository,
    InMemoryDatabase,
    InMemorySesionesRepository,
    InMemoryUsuariosRepository,
    StubMongo,
)
from tests.support import FixedClock, MemoryLogger, make_settings

CORREO = "ana@uptc.edu.co"
PASSWORD = "Clave-Segura-2026"
IP = "203.0.113.10"


class AuthEnv:
    def __init__(self) -> None:
        self.db = InMemoryDatabase()
        self.credenciales = InMemoryCredencialesRepository(self.db)
        self.clock = FixedClock()
        self.logger = MemoryLogger()

    def build_container(self, **settings: Any) -> Container:
        config = make_settings(
            **{"rate_limit_login_max": 100, "rate_limit_forgot_max": 100, **settings}
        )
        return Container.build(
            config,
            clock=self.clock,
            logger=self.logger,
            mongo=StubMongo(),
            credenciales=self.credenciales,
            usuarios=InMemoryUsuariosRepository(self.db),
            sesiones=InMemorySesionesRepository(self.db),
        )

    def client(self, container: Container | None = None, **settings: Any) -> TestClient:
        container = container or self.build_container(**settings)
        return TestClient(create_app(container.settings, container), client=(IP, 50000))

    @staticmethod
    def login(client: TestClient, correo: str = CORREO, password: str = PASSWORD) -> httpx.Response:
        response: httpx.Response = client.post(
            "/api/v1/auth/login", json={"correo": correo, "password": password}
        )
        return response

    def token(self, client: TestClient) -> str:
        response = self.login(client)
        assert response.status_code == 200, response.text
        token: str = response.json()["token"]
        return token


def assert_error(response: httpx.Response, status: int, code: str) -> dict[str, Any]:
    assert response.status_code == status, response.text
    assert response.headers["content-type"] == "application/json; charset=utf-8"
    error: dict[str, Any] = response.json()["error"]
    assert error["code"] == code
    assert isinstance(error["message"], str)
    return error


def bearer(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}
