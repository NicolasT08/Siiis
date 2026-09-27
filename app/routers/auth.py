"""Endpoints de autenticación local (spec 10.7 y 11.3). Login con Google: fuera de la fase 1."""

from typing import Annotated

from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response

from app.auth import AuthContext, require_auth
from app.client_ip import client_ip
from app.container import Container, get_container
from app.errors import ApiError
from app.request_body import parsed_body
from app.responses import json_ok, no_content
from app.validation import validate_login

router = APIRouter(prefix="/auth")

ContainerDep = Annotated[Container, Depends(get_container)]
AuthDep = Annotated[AuthContext, Depends(require_auth)]


def limit(container: Container, action: str, ip: str, max_hits: int, window: int) -> None:
    retry_after = container.rate_limiter.hit(action, ip, max_hits, window)
    if retry_after > 0:
        raise ApiError.rate_limited(retry_after)


@router.post("/login")
def login(request: Request, container: ContainerDep) -> Response:
    ip = client_ip(request)
    settings = container.settings
    limit(
        container,
        "login",
        ip,
        settings.rate_limit_login_max,
        settings.rate_limit_login_window_seconds,
    )

    datos = validate_login(parsed_body(request))
    resultado = container.auth.login(datos.correo, datos.password, ip)
    return json_ok(resultado, headers={"Cache-Control": "no-store"})


@router.get("/me")
def me(container: ContainerDep, auth: AuthDep) -> Response:
    usuario = container.auth.usuario_actual(auth.usuario_id)
    return json_ok({"usuario": usuario}, headers={"Cache-Control": "no-store"})


@router.post("/logout")
def logout(container: ContainerDep, auth: AuthDep) -> Response:
    """PROPUESTA DEC-B05: cierra solo la sesión del token enviado."""
    container.auth.logout(auth.session_hash)
    return no_content()
