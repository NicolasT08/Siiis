"""Protección de rutas con JWT + sesión activa en `Sesiones` (réplica de AuthMiddleware.php).

El rol sale del token firmado, nunca del cuerpo de la petición.
"""

import re
from dataclasses import dataclass
from typing import Annotated

from fastapi import Depends, Request

from app.container import Container, get_container
from app.errors import ApiError
from app.security import InvalidTokenError, hash_token

# preg_match('/^Bearer\s+(\S+)$/i', trim($header)) de PHP.
_BEARER_RE = re.compile(r"Bearer\s+(\S+)", re.IGNORECASE | re.ASCII)
_PHP_TRIM = " \t\n\r\0\x0b"


@dataclass(frozen=True)
class AuthContext:
    usuario_id: str
    rol: str
    session_hash: str


def bearer_token(request: Request) -> str | None:
    match = _BEARER_RE.fullmatch(request.headers.get("authorization", "").strip(_PHP_TRIM))
    return match.group(1) if match else None


def require_auth(
    request: Request, container: Annotated[Container, Depends(get_container)]
) -> AuthContext:
    token = bearer_token(request)
    if token is None:
        raise ApiError.unauthorized()

    try:
        claims = container.jwt.decode(token)
    except InvalidTokenError:
        raise ApiError.unauthorized() from None

    session_hash = hash_token(claims.jti)
    sesion = container.sesiones.find_activa(session_hash, container.clock.now())
    if sesion is None or str(sesion.get("usuario_id", "")) != claims.sub:
        raise ApiError.unauthorized()

    return AuthContext(claims.sub, claims.rol, session_hash)
