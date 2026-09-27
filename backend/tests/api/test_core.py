"""Comportamiento transversal de la API: formato de errores, cuerpo JSON y CORS."""

from typing import Any

from fastapi import APIRouter, Depends, FastAPI, Request
from fastapi.testclient import TestClient
from pymongo.errors import OperationFailure, ServerSelectionTimeoutError

from app.application import API_PREFIX, create_app
from app.container import Container
from app.request_body import parse_body, parsed_body
from tests.support import FixedClock, MemoryLogger, make_settings


def app_de_prueba(**settings: Any) -> tuple[FastAPI, MemoryLogger]:
    """App real con rutas auxiliares de prueba (los endpoints reales llegan en pasos siguientes)."""
    config = make_settings(**settings)
    logger = MemoryLogger()
    app = create_app(config, Container.build(config, clock=FixedClock(), logger=logger))

    router = APIRouter(prefix=API_PREFIX, dependencies=[Depends(parse_body)])

    @router.get("/prueba")
    def prueba() -> dict[str, str]:
        return {"ok": "sí"}

    @router.post("/eco")
    def eco(request: Request) -> dict[str, Any]:
        return {"body": parsed_body(request)}

    @router.get("/falla")
    def falla() -> None:
        raise RuntimeError("detalle interno secreto")

    @router.get("/sin-bd")
    def sin_bd() -> None:
        raise ServerSelectionTimeoutError("No suitable servers found")

    @router.get("/sin-auth-bd")
    def sin_auth_bd() -> None:
        raise OperationFailure("Authentication failed.", code=18)

    app.include_router(router)
    return app, logger


def assert_error(response: Any, status: int, code: str) -> dict[str, Any]:
    assert response.status_code == status, response.text
    assert response.headers["content-type"] == "application/json; charset=utf-8"
    body: dict[str, Any] = response.json()
    assert set(body) == {"error"}
    assert body["error"]["code"] == code
    assert isinstance(body["error"]["message"], str)
    error: dict[str, Any] = body["error"]
    return error


def test_respuesta_json_con_charset_y_sin_escapar_unicode() -> None:
    app, _ = app_de_prueba()
    response = TestClient(app).get("/api/v1/prueba")

    assert response.status_code == 200
    assert response.content == '{"ok":"sí"}'.encode()


def test_ruta_inexistente_devuelve_404_json() -> None:
    app, _ = app_de_prueba()
    client = TestClient(app)

    for ruta in ["/api/v1/no-existe", "/api/v1/auth/google", "/", "/docs", "/openapi.json"]:
        error = assert_error(client.get(ruta), 404, "NOT_FOUND")
        assert error == {"code": "NOT_FOUND", "message": "El recurso solicitado no existe."}


def test_barra_final_no_redirige() -> None:
    app, _ = app_de_prueba()
    response = TestClient(app).get("/api/v1/prueba/", follow_redirects=False)

    assert_error(response, 404, "NOT_FOUND")


def test_metodo_no_permitido_devuelve_405_json() -> None:
    app, _ = app_de_prueba()
    response = TestClient(app).delete("/api/v1/prueba")

    error = assert_error(response, 405, "METHOD_NOT_ALLOWED")
    assert error["message"] == "Método HTTP no permitido para esta ruta."
    assert response.headers["allow"] == "GET"


def test_json_invalido() -> None:
    app, _ = app_de_prueba()
    client = TestClient(app)
    headers = {"Content-Type": "application/json"}

    error = assert_error(
        client.post("/api/v1/eco", content=b"{", headers=headers), 400, "INVALID_JSON"
    )
    assert error["message"] == "El cuerpo de la petición no es un JSON válido."

    error = assert_error(
        client.post("/api/v1/eco", content=b"[]", headers=headers), 400, "INVALID_JSON"
    )
    assert error["message"] == "El cuerpo de la petición debe ser un objeto JSON."


def test_json_invalido_tambien_en_get_y_404_tiene_prioridad() -> None:
    app, _ = app_de_prueba()
    client = TestClient(app)
    headers = {"Content-Type": "application/json"}

    assert_error(
        client.request("GET", "/api/v1/prueba", content=b"{", headers=headers), 400, "INVALID_JSON"
    )
    assert_error(client.post("/api/v1/no-existe", content=b"{", headers=headers), 404, "NOT_FOUND")


def test_cuerpo_segun_content_type() -> None:
    app, _ = app_de_prueba()
    client = TestClient(app)

    assert client.post("/api/v1/eco", json={"a": 1}).json() == {"body": {"a": 1}}
    assert client.post(
        "/api/v1/eco", content=b"", headers={"Content-Type": "application/json"}
    ).json() == {"body": {}}
    assert client.post(
        "/api/v1/eco", content=b"{", headers={"Content-Type": "text/plain"}
    ).json() == {"body": None}
    assert client.post("/api/v1/eco", data={"correo": "a@b.co"}).json() == {
        "body": {"correo": "a@b.co"}
    }


def test_error_interno_sin_trazas_cuando_debug_es_false() -> None:
    app, logger = app_de_prueba(app_debug=False)
    response = TestClient(app).get("/api/v1/falla")

    error = assert_error(response, 500, "INTERNAL_ERROR")
    assert error == {"code": "INTERNAL_ERROR", "message": "Ocurrió un error interno."}
    assert "detalle interno secreto" not in response.text
    assert logger.records[0][:2] == ("ERROR", "Error no controlado")


def test_error_interno_con_detalle_cuando_debug_es_true() -> None:
    app, _ = app_de_prueba(app_debug=True)
    response = TestClient(app).get("/api/v1/falla")

    error = assert_error(response, 500, "INTERNAL_ERROR")
    assert error["debug"]["message"] == "detalle interno secreto"
    assert error["debug"]["exception"] == "builtins.RuntimeError"
    assert isinstance(error["debug"]["trace"], list)


def test_fallo_de_conexion_a_mongo_devuelve_503() -> None:
    app, logger = app_de_prueba()
    client = TestClient(app)

    for ruta in ["/api/v1/sin-bd", "/api/v1/sin-auth-bd"]:
        error = assert_error(client.get(ruta), 503, "DB_UNAVAILABLE")
        assert error["message"] == "La base de datos no está disponible. Intenta más tarde."
    assert logger.records[0][1] == "Base de datos no disponible"


def test_cors_preflight_en_desarrollo() -> None:
    app, _ = app_de_prueba(app_env="development")
    response = TestClient(app).options(
        "/api/v1/auth/login",
        headers={"Origin": "http://localhost:5173", "Access-Control-Request-Method": "POST"},
    )

    assert response.status_code == 204
    assert response.headers["access-control-allow-origin"] == "http://localhost:5173"
    assert "Authorization" in response.headers["access-control-allow-headers"]
    assert response.headers["access-control-max-age"] == "600"
    assert response.headers["vary"] == "Origin"


def test_cors_en_respuestas_normales_y_de_error() -> None:
    app, _ = app_de_prueba(app_env="development")
    client = TestClient(app)
    origen = {"Origin": "http://localhost:5173"}

    for ruta in ["/api/v1/prueba", "/api/v1/no-existe", "/api/v1/falla"]:
        response = client.get(ruta, headers=origen)
        assert response.headers["access-control-allow-origin"] == "http://localhost:5173"
        assert response.headers["vary"] == "Origin"


def test_cors_rechaza_origen_no_permitido() -> None:
    app, _ = app_de_prueba(app_env="development")
    client = TestClient(app)

    preflight = client.options("/api/v1/prueba", headers={"Origin": "https://evil.example"})
    assert preflight.status_code == 204
    assert "access-control-allow-origin" not in preflight.headers

    response = client.get("/api/v1/prueba", headers={"Origin": "https://evil.example"})
    assert "access-control-allow-origin" not in response.headers
    assert response.headers["vary"] == "Origin"


def test_sin_cors_fuera_de_desarrollo() -> None:
    app, _ = app_de_prueba(app_env="production")
    client = TestClient(app)

    response = client.get("/api/v1/prueba", headers={"Origin": "http://localhost:5173"})
    assert "access-control-allow-origin" not in response.headers

    # Sin CORS, OPTIONS pasa al enrutador: 405 como en Slim.
    assert_error(client.options("/api/v1/prueba"), 405, "METHOD_NOT_ALLOWED")
