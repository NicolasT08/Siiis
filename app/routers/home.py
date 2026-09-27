"""Recursos públicos de la página de inicio (spec 10.12): slider (RF-07) y videos (RF-11).

Hero, bloques S-I-I-I-S, CTA y footer no tienen backend en la fase 1 (DEC-B12).
"""

from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import Response

from app.container import Container, get_container
from app.repositories.multimedia import SECCION_SLIDER, SECCION_VIDEOS
from app.responses import json_ok
from app.serializers import serialize

CACHE_CONTROL = "public, max-age=300"

router = APIRouter(prefix="/home")


def _seccion(container: Container, seccion: str) -> Response:
    docs = container.multimedia.find_by_seccion(seccion, container.settings.home_max_items)
    items = [
        serialize(
            {
                "_id": doc.get("_id"),
                "tipo": doc.get("tipo"),
                "url": doc.get("url"),
                "descripcion": doc.get("descripcion"),
                "orden": doc.get("orden"),
            }
        )
        for doc in docs
    ]
    return json_ok({"data": items}, headers={"Cache-Control": CACHE_CONTROL})


@router.get("/slider")
def slider(container: Annotated[Container, Depends(get_container)]) -> Response:
    return _seccion(container, SECCION_SLIDER)


@router.get("/videos")
def videos(container: Annotated[Container, Depends(get_container)]) -> Response:
    return _seccion(container, SECCION_VIDEOS)
