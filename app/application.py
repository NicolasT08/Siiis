"""Construye la aplicación FastAPI: rutas bajo /api/v1, manejadores de error y middleware.

Réplica de `Config/Application.php`. Orden de ejecución, de afuera hacia adentro:
CORS (solo desarrollo) → ErrorBoundary → enrutamiento (404/405) → cuerpo JSON → ruta.
"""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import APIRouter, Depends, FastAPI

from app.config import Settings
from app.container import Container
from app.cors import CorsMiddleware
from app.errors import ErrorBoundaryMiddleware, register_exception_handlers
from app.request_body import parse_body

API_PREFIX = "/api/v1"


def build_api_router() -> APIRouter:
    """Router /api/v1. Las rutas de cada módulo se registran aquí."""
    return APIRouter(prefix=API_PREFIX, dependencies=[Depends(parse_body)])


def create_app(settings: Settings, container: Container | None = None) -> FastAPI:
    container = container or Container.build(settings)

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        yield
        container.mongo.close()

    app = FastAPI(
        title="SIIIS V3 API (Python)",
        # PHP no expone documentación automática: esas rutas responden 404.
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
        # Slim no redirige `/ruta/` → `/ruta`; responde 404.
        redirect_slashes=False,
        lifespan=lifespan,
    )
    app.state.container = container

    register_exception_handlers(app)
    app.include_router(build_api_router())

    # Starlette ejecuta primero el último middleware añadido.
    app.add_middleware(ErrorBoundaryMiddleware, logger=container.logger, debug=settings.app_debug)
    if settings.is_development:
        app.add_middleware(CorsMiddleware, allowed_origin=settings.frontend_origin)

    return app
