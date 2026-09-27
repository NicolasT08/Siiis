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
from app.validation import (
    validate_forgot_password,
    validate_login,
    validate_reset_password,
)

router = APIRouter(prefix="/auth")

MENSAJE_FORGOT = "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña."
MENSAJE_RESET = "Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña."

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


@router.post("/forgot-password")
def forgot_password(request: Request, container: ContainerDep) -> Response:
    """Siempre la misma respuesta, exista o no el correo."""
    ip = client_ip(request)
    settings = container.settings
    limit(
        container,
        "forgot",
        ip,
        settings.rate_limit_forgot_max,
        settings.rate_limit_forgot_window_seconds,
    )

    correo = validate_forgot_password(parsed_body(request))
    container.password_reset.solicitar(correo)
    return json_ok({"mensaje": MENSAJE_FORGOT})


@router.post("/reset-password")
def reset_password(request: Request, container: ContainerDep) -> Response:
    datos = validate_reset_password(parsed_body(request), container.settings.password_min_length)
    container.password_reset.restablecer(datos.token, datos.password)
    return json_ok({"mensaje": MENSAJE_RESET})
