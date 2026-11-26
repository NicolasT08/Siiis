from typing import Any

import httpx
import pytest

from app.database import Collections
from tests.api.auth_env import AuthEnv, assert_error
from tests.fakes import StubMongo


class InMemoryInscripciones:
    def __init__(self) -> None:
        self.documents: list[dict[str, Any]] = []

    def insert_one(self, document: dict[str, Any]) -> None:
        self.documents.append(document)


class InscripcionesMongo(StubMongo):
    def __init__(self) -> None:
        super().__init__()
        self.inscripciones = InMemoryInscripciones()

    def collection(self, name: str) -> InMemoryInscripciones:
        assert name == Collections.INSCRIPCIONES
        return self.inscripciones


@pytest.fixture
def env() -> AuthEnv:
    return AuthEnv()


def test_inscripcion_guarda_solicitud_pendiente(env: AuthEnv) -> None:
    mongo = InscripcionesMongo()
    container = env.build_container()
    container.mongo = mongo
    response = env.client(container).post(
        "/api/v1/inscripciones",
        json={
            "nombre": "Ana Pérez",
            "correo": "ANA@UPTC.EDU.CO",
            "codigo_estudiantil": "20261001",
            "programa_academico": "Ingeniería de Sistemas",
            "semestre": 4,
            "areas_interes": ["desarrollo_software", "innovacion"],
            "motivacion": "Quiero aprender y aportar en proyectos de software.",
            "disponibilidad": True,
        },
    )

    assert response.status_code == 200
    assert response.json()["mensaje"].startswith("Recibimos tu solicitud")
    assert len(mongo.inscripciones.documents) == 1
    solicitud = mongo.inscripciones.documents[0]
    assert solicitud["correo"] == "ana@uptc.edu.co"
    assert solicitud["areas_interes"] == ["desarrollo_software", "innovacion"]
    assert solicitud["estado_solicitud"] == "pendiente"


def test_inscripcion_rechaza_areas_invalidas_sin_guardar(env: AuthEnv) -> None:
    mongo = InscripcionesMongo()
    container = env.build_container()
    container.mongo = mongo
    response: httpx.Response = env.client(container).post(
        "/api/v1/inscripciones",
        json={
            "nombre": "Ana Pérez",
            "correo": "ana@uptc.edu.co",
            "codigo_estudiantil": "20261001",
            "programa_academico": "Ingeniería de Sistemas",
            "semestre": 4,
            "areas_interes": ["otra_area"],
            "motivacion": "Quiero aprender y aportar en proyectos.",
            "disponibilidad": True,
        },
    )

    error = assert_error(response, 400, "VALIDATION_ERROR")
    assert "areas_interes" in error["fields"]
    assert mongo.inscripciones.documents == []
