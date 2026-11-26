"""Recepcion publica de mensajes de contacto."""

from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response

from app.container import Container, get_container
from app.database import Collections
from app.errors import ApiError
from app.request_body import parsed_body
from app.responses import json_ok
from app.validation import php_email_valido

router = APIRouter(prefix="/contacto")
ContainerDep = Annotated[Container, Depends(get_container)]


@router.post("")
def enviar(request: Request, container: ContainerDep) -> Response:
    datos: dict[str, Any] = parsed_body(request)
    correo = datos.get("correo")
    mensaje = datos.get("mensaje")

    fields: dict[str, str] = {}
    if not isinstance(correo, str) or not correo.strip() or not php_email_valido(correo.strip()):
        fields["correo"] = "Ingresa un correo electrónico válido."
    if not isinstance(mensaje, str) or not mensaje.strip():
        fields["mensaje"] = "El mensaje es obligatorio."
    elif len(mensaje.strip()) > 2000:
        fields["mensaje"] = "El mensaje no puede superar los 2000 caracteres."
    if fields:
        raise ApiError.validation(fields)

    container.mongo.collection(Collections.MENSAJES_CONTACTO).insert_one({
        "correo": correo.strip(),
        "mensaje": mensaje.strip(),
        "fecha": datetime.now(UTC),
        "estado": "pendiente",
    })
    return json_ok({"mensaje": "Tu mensaje fue enviado correctamente."})
