"""GET /api/v1/health — estado de la API y ping a MongoDB Atlas (spec 10.1 y 27)."""

from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import Response

from app.container import Container, get_container
from app.responses import json_error, json_ok

router = APIRouter()


@router.get("/health")
def health(container: Annotated[Container, Depends(get_container)]) -> Response:
    if not container.mongo.ping():
        return json_error("DB_UNAVAILABLE", "La base de datos no está disponible.", 503)
    return json_ok({"status": "ok", "db": "ok"}, headers={"Cache-Control": "no-store"})
