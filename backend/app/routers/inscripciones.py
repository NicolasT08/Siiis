"""Recepción pública de solicitudes de ingreso al semillero."""

from typing import Annotated

from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response

from app.client_ip import client_ip
from app.container import Container, get_container
from app.database import Collections
from app.errors import ApiError
from app.request_body import parsed_body
from app.responses import json_ok
from app.validation import validate_inscripcion

router = APIRouter(prefix="/inscripciones")
ContainerDep = Annotated[Container, Depends(get_container)]
MAX_SOLICITUDES_POR_HORA = 5


@router.post("")
def crear_inscripcion(request: Request, container: ContainerDep) -> Response:
    retry_after = container.rate_limiter.hit(
        "inscripcion",
        client_ip(request),
        MAX_SOLICITUDES_POR_HORA,
        3600,
    )
    if retry_after > 0:
        raise ApiError.rate_limited(retry_after)

    datos = validate_inscripcion(parsed_body(request))
    container.mongo.collection(Collections.INSCRIPCIONES).insert_one(
        {
            "nombre": datos.nombre,
            "correo": datos.correo,
            "codigo_estudiantil": datos.codigo_estudiantil,
            "programa_academico": datos.programa_academico,
            "semestre": datos.semestre,
            "areas_interes": datos.areas_interes,
            "motivacion": datos.motivacion,
            "disponibilidad": True,
            "estado_solicitud": "pendiente",
            "fecha_solicitud": container.clock.now(),
        }
    )
    return json_ok({"mensaje": "Recibimos tu solicitud de inscripción. ¡Gracias por tu interés!"})
