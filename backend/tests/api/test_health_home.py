import re
from datetime import UTC, datetime
from typing import Any

from bson import ObjectId
from fastapi.testclient import TestClient
from pymongo.errors import ServerSelectionTimeoutError

from app.application import create_app
from app.container import Container
from app.database import Document
from tests.fakes import InMemoryMultimediaRepository, StubMongo
from tests.support import MemoryLogger, make_settings


def cliente(
    mongo: StubMongo | None = None,
    multimedia: InMemoryMultimediaRepository | None = None,
    **settings: Any,
) -> TestClient:
    config = make_settings(**settings)
    container = Container.build(
        config, logger=MemoryLogger(), mongo=mongo or StubMongo(), multimedia=multimedia
    )
    return TestClient(create_app(config, container))


# --- Health ------------------------------------------------------------------------------------


def test_health_ok_cuando_mongo_responde() -> None:
    response = cliente(StubMongo(True)).get("/api/v1/health")

    assert response.status_code == 200
    assert response.content == b'{"status":"ok","db":"ok"}'
    assert response.headers["cache-control"] == "no-store"
    assert response.headers["content-type"] == "application/json; charset=utf-8"


def test_health_503_cuando_mongo_no_responde() -> None:
    response = cliente(StubMongo(False)).get("/api/v1/health")

    assert response.status_code == 503
    assert response.json() == {
        "error": {"code": "DB_UNAVAILABLE", "message": "La base de datos no está disponible."}
    }
    assert "cache-control" not in response.headers


def test_health_error_de_conexion_devuelve_503() -> None:
    response = cliente(StubMongo(ServerSelectionTimeoutError("sin servidores"))).get(
        "/api/v1/health"
    )

    assert response.status_code == 503
    assert response.json()["error"]["code"] == "DB_UNAVAILABLE"


def test_health_error_inesperado_devuelve_500_sin_detalle() -> None:
    response = cliente(StubMongo(RuntimeError("detalle interno secreto"))).get("/api/v1/health")

    assert response.status_code == 500
    assert response.json() == {
        "error": {"code": "INTERNAL_ERROR", "message": "Ocurrió un error interno."}
    }


def test_health_metodo_no_permitido() -> None:
    response = cliente().delete("/api/v1/health")

    assert response.status_code == 405
    assert response.json()["error"]["code"] == "METHOD_NOT_ALLOWED"
    assert response.headers["allow"] == "GET"


# --- Home --------------------------------------------------------------------------------------


def docs() -> list[Document]:
    def doc(seccion: str, tipo: str, orden: int) -> Document:
        return {
            "_id": ObjectId(),
            "tipo": tipo,
            "url": f"https://res.cloudinary.com/demo/{tipo}/upload/{seccion}-{orden}",
            "public_id": f"secreto-{seccion}-{orden}",
            "seccion": seccion,
            "descripcion": f"Ítem {orden}",
            "orden": orden,
            "fecha_subida": datetime.now(UTC),
        }

    return [
        doc("slider_principal", "imagen", 3),
        doc("video_presentacion", "video", 2),
        doc("slider_principal", "imagen", 1),
        doc("galeria", "imagen", 1),
        doc("slider_principal", "imagen", 2),
        doc("video_presentacion", "video", 1),
    ]


def test_slider_ordenado_con_proyeccion_y_cache() -> None:
    response = cliente(multimedia=InMemoryMultimediaRepository(docs())).get("/api/v1/home/slider")

    assert response.status_code == 200
    assert response.headers["cache-control"] == "public, max-age=300"
    data = response.json()["data"]
    assert [item["orden"] for item in data] == [1, 2, 3]
    for item in data:
        assert list(item) == ["id", "tipo", "url", "descripcion", "orden"]
        assert re.fullmatch(r"[a-f0-9]{24}", item["id"])
        assert item["tipo"] == "imagen"
    assert "secreto" not in response.text
    assert "public_id" not in response.text
    assert "Ítem" in response.text  # sin escapar Unicode, como JSON_UNESCAPED_UNICODE


def test_videos_ordenados() -> None:
    response = cliente(multimedia=InMemoryMultimediaRepository(docs())).get("/api/v1/home/videos")

    data = response.json()["data"]
    assert [item["orden"] for item in data] == [1, 2]
    assert [item["tipo"] for item in data] == ["video", "video"]


def test_aplica_el_tope_configurable() -> None:
    repo = InMemoryMultimediaRepository(docs())
    response = cliente(multimedia=repo, home_max_items=2).get("/api/v1/home/slider")

    assert len(response.json()["data"]) == 2
    assert repo.llamadas == [("slider_principal", 2)]


def test_seccion_vacia_devuelve_lista_vacia() -> None:
    response = cliente(multimedia=InMemoryMultimediaRepository([])).get("/api/v1/home/videos")

    assert response.status_code == 200
    assert response.content == b'{"data":[]}'


def test_campos_faltantes_salen_como_null() -> None:
    repo = InMemoryMultimediaRepository(
        [{"_id": ObjectId("6ab693b1ccc363bbc95e1342"), "seccion": "slider_principal", "orden": 1}]
    )
    response = cliente(multimedia=repo).get("/api/v1/home/slider")

    assert response.json() == {
        "data": [
            {
                "id": "6ab693b1ccc363bbc95e1342",
                "tipo": None,
                "url": None,
                "descripcion": None,
                "orden": 1,
            }
        ]
    }
