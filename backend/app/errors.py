"""Errores de la API con el formato de `docs/api.md`: {"error": {"code", "message", "fields"?}}.

Réplica de `Support/ApiException` y `Middleware/ErrorHandler` del backend PHP (mismos códigos,
status y textos).
"""

import traceback
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from pymongo.errors import ConnectionFailure, OperationFailure
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.responses import Response
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.logger import Logger
from app.responses import ApiJSONResponse, json_error

VALIDATION_MESSAGE = "Los datos enviados no son válidos."
UNAUTHORIZED_MESSAGE = "Autenticación requerida o sesión no válida."
RATE_LIMITED_MESSAGE = "Demasiadas solicitudes. Intenta de nuevo más tarde."
NOT_FOUND_MESSAGE = "El recurso solicitado no existe."
METHOD_NOT_ALLOWED_MESSAGE = "Método HTTP no permitido para esta ruta."
DB_UNAVAILABLE_MESSAGE = "La base de datos no está disponible. Intenta más tarde."
INTERNAL_ERROR_MESSAGE = "Ocurrió un error interno."

# Código de MongoDB para "AuthenticationFailed" (en PHP es una ConnectionException).
_MONGO_AUTH_FAILED = 18


class ApiError(Exception):
    """Error de negocio/HTTP con código estable para el frontend."""

    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        fields: dict[str, str] | None = None,
        headers: dict[str, str] | None = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message
        self.fields = fields or {}
        self.headers = headers or {}

    @classmethod
    def validation(cls, fields: dict[str, str], message: str = VALIDATION_MESSAGE) -> "ApiError":
        return cls(400, "VALIDATION_ERROR", message, fields)

    @classmethod
    def unauthorized(cls, message: str = UNAUTHORIZED_MESSAGE) -> "ApiError":
        return cls(401, "UNAUTHORIZED", message)

    @classmethod
    def rate_limited(cls, retry_after_seconds: int) -> "ApiError":
        return cls(
            429,
            "RATE_LIMITED",
            RATE_LIMITED_MESSAGE,
            headers={"Retry-After": str(retry_after_seconds)},
        )


def is_db_unavailable(exc: BaseException) -> bool:
    """Equivalente a `MongoDB\\Driver\\Exception\\ConnectionException` de PHP."""
    if isinstance(exc, ConnectionFailure):
        return True
    return isinstance(exc, OperationFailure) and exc.code == _MONGO_AUTH_FAILED


async def _api_error_handler(_: Request, exc: Exception) -> Response:
    assert isinstance(exc, ApiError)  # noqa: S101
    return json_error(exc.code, exc.message, exc.status, exc.fields, exc.headers)


async def _http_exception_handler(_: Request, exc: Exception) -> Response:
    assert isinstance(exc, StarletteHTTPException)  # noqa: S101
    if exc.status_code == 404:
        return json_error("NOT_FOUND", NOT_FOUND_MESSAGE, 404)
    if exc.status_code == 405:
        allow = (exc.headers or {}).get("Allow", "")
        # Starlette agrega HEAD a las rutas GET; Slim solo informa los métodos declarados.
        metodos = [m.strip() for m in allow.split(",") if m.strip() and m.strip() != "HEAD"]
        return json_error(
            "METHOD_NOT_ALLOWED",
            METHOD_NOT_ALLOWED_MESSAGE,
            405,
            headers={"Allow": ", ".join(metodos)},
        )
    return json_error("HTTP_ERROR", str(exc.detail), exc.status_code)


async def _request_validation_handler(_: Request, exc: Exception) -> Response:
    # Las rutas validan con app.validation (textos de PHP); esto solo cubre el 422 por defecto.
    assert isinstance(exc, RequestValidationError)  # noqa: S101
    fields = {str(err["loc"][-1]): str(err["msg"]) for err in exc.errors() if err.get("loc")}
    return json_error("VALIDATION_ERROR", VALIDATION_MESSAGE, 400, fields)


def register_exception_handlers(app: FastAPI) -> None:
    app.add_exception_handler(ApiError, _api_error_handler)
    app.add_exception_handler(StarletteHTTPException, _http_exception_handler)
    app.add_exception_handler(RequestValidationError, _request_validation_handler)


class ErrorBoundaryMiddleware:
    """Convierte cualquier excepción no controlada en JSON (DB_UNAVAILABLE o INTERNAL_ERROR).

    Va dentro del middleware CORS, como el ErrorMiddleware de Slim, para que esas respuestas
    también lleven las cabeceras CORS en desarrollo.
    """

    def __init__(self, app: ASGIApp, logger: Logger, debug: bool) -> None:
        self.app = app
        self.logger = logger
        self.debug = debug

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        started = False

        async def send_wrapper(message: Message) -> None:
            nonlocal started
            if message["type"] == "http.response.start":
                started = True
            await send(message)

        try:
            await self.app(scope, receive, send_wrapper)
        except Exception as exc:
            if started:
                raise
            response = self._render(scope, exc)
            await response(scope, receive, send)

    def _render(self, scope: Scope, exc: Exception) -> Response:
        context = self._context(scope, exc)
        if is_db_unavailable(exc):
            self.logger.error("Base de datos no disponible", context)
            return json_error("DB_UNAVAILABLE", DB_UNAVAILABLE_MESSAGE, 503)

        trace = traceback.format_exception(exc)
        self.logger.error("Error no controlado", {**context, "trace": "".join(trace)})
        if not self.debug:
            return json_error("INTERNAL_ERROR", INTERNAL_ERROR_MESSAGE, 500)

        body: dict[str, Any] = {
            "error": {
                "code": "INTERNAL_ERROR",
                "message": INTERNAL_ERROR_MESSAGE,
                "debug": {
                    "exception": context["exception"],
                    "message": str(exc),
                    "file": context["file"],
                    "trace": "".join(trace).splitlines(),
                },
            }
        }
        return ApiJSONResponse(body, status_code=500)

    @staticmethod
    def _context(scope: Scope, exc: Exception) -> dict[str, str]:
        frames = traceback.extract_tb(exc.__traceback__)
        origen = f"{frames[-1].filename}:{frames[-1].lineno}" if frames else "?"
        return {
            "method": str(scope.get("method", "")),
            "path": str(scope.get("path", "")),
            "exception": f"{type(exc).__module__}.{type(exc).__qualname__}",
            "message": str(exc),
            "file": origen,
        }
