"""Contraseñas (bcrypt), JWT HS256 y tokens aleatorios, compatibles con el backend PHP.

- Hashes: Python genera `$2b$` y verifica los `$2y$` de PHP (mismo algoritmo, coste 10).
- JWT: mismos claims que `firebase/php-jwt` en PHP (`sub`, `rol`, `jti`, `iat`, `exp`), mismo
  secreto y duración máxima de 8 horas, así que un token de un backend sirve en el otro.
"""

import hashlib
import math
import re
import secrets
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Any

import bcrypt
import jwt

from app.clock import Clock
from app.serializers import is_object_id

MAX_JWT_TTL_SECONDS = 28800
"""RNF-05: la sesión dura como máximo 8 horas."""

BCRYPT_COST = 10
"""Coste por defecto de `password_hash()` en PHP 8.2."""

BCRYPT_MAX_BYTES = 72

_BCRYPT_RE = re.compile(r"\$2[aby]\$(\d{2})\$[./A-Za-z0-9]{53}", re.ASCII)
_INT64_MIN, _INT64_MAX = -(2**63), 2**63 - 1


class PasswordHasher:
    # Hash fijo del backend PHP para igualar tiempos cuando el correo no existe (DEC-B07).
    # Corresponde a una contraseña aleatoria descartada; nunca valida nada real.
    DUMMY_HASH = "$2y$10$kOGVwDHrqBjdNATCSkOxa.YMP4kf/V63g.dL2MoZnSmdqAVqmsGUO"

    def hash(self, password: str) -> str:
        secreto = password.encode("utf-8")
        if b"\0" in secreto:
            # PHP 8.2: password_hash() lanza ValueError con un NUL (termina en 500).
            raise ValueError("Bcrypt password must not contain null character")
        return bcrypt.hashpw(_bcrypt_input(secreto), bcrypt.gensalt(BCRYPT_COST)).decode("ascii")

    def verify(self, password: str, hashed: str) -> bool:
        try:
            return bcrypt.checkpw(_bcrypt_input(password.encode("utf-8")), hashed.encode("ascii"))
        except ValueError:
            # Hash mal formado o de otro algoritmo: password_verify() devuelve false.
            return False

    def needs_rehash(self, hashed: str) -> bool:
        """True si el hash no es bcrypt de coste 10.

        A diferencia de PHP, `$2b$` se acepta como vigente: si no, Python rehashearía sus propios
        hashes en cada login. PHP sí rehashea `$2b$` → `$2y$` en su siguiente login (inofensivo).
        """
        match = _BCRYPT_RE.fullmatch(hashed)
        return match is None or hashed[:4] == "$2a$" or int(match.group(1)) != BCRYPT_COST

    def dummy_verify(self, password: str) -> None:
        """Ejecuta una verificación con coste equivalente y descarta el resultado."""
        self.verify(password, self.DUMMY_HASH)


def _bcrypt_input(secreto: bytes) -> bytes:
    """Igual que PHP: bcrypt usa la contraseña como cadena C (hasta el primer NUL) y 72 bytes."""
    return secreto.split(b"\0", 1)[0][:BCRYPT_MAX_BYTES]


class InvalidTokenError(Exception):
    """JWT con firma inválida, expirado, mal formado o con claims incompletos."""


@dataclass(frozen=True)
class TokenClaims:
    sub: str
    rol: str
    jti: str
    iat: int
    exp: int


@dataclass(frozen=True)
class IssuedToken:
    token: str
    jti: str
    issued_at: datetime
    expires_at: datetime

    @property
    def expires_in(self) -> int:
        return int(self.expires_at.timestamp()) - int(self.issued_at.timestamp())


class JwtService:
    ALGORITHM = "HS256"

    def __init__(self, secret: str, ttl_seconds: int, clock: Clock) -> None:
        self._secret = secret
        self._ttl = max(1, min(ttl_seconds, MAX_JWT_TTL_SECONDS))
        self._clock = clock

    @property
    def ttl_seconds(self) -> int:
        return self._ttl

    def issue(self, user_id: str, rol: str) -> IssuedToken:
        issued_at = self._clock.now()
        expires_at = issued_at + timedelta(seconds=self._ttl)
        jti = secrets.token_hex(16)
        token = jwt.encode(
            {
                "sub": user_id,
                "rol": rol,
                "jti": jti,
                "iat": math.floor(issued_at.timestamp()),
                "exp": math.floor(expires_at.timestamp()),
            },
            self._secret,
            algorithm=self.ALGORITHM,
        )
        return IssuedToken(token, jti, issued_at, expires_at)

    def decode(self, token: str) -> TokenClaims:
        """Valida firma, algoritmo y tiempos (con el reloj inyectado) y devuelve los claims."""
        try:
            payload: dict[str, Any] = jwt.decode(
                token,
                self._secret,
                algorithms=[self.ALGORITHM],
                # Los tiempos se validan abajo con el reloj inyectable, como firebase/php-jwt.
                options={
                    "verify_exp": False,
                    "verify_iat": False,
                    "verify_nbf": False,
                    "verify_aud": False,
                    "verify_iss": False,
                    "verify_sub": False,
                    "verify_jti": False,
                },
            )
        except jwt.PyJWTError as exc:
            raise InvalidTokenError("Token inválido o expirado.") from exc

        now = math.floor(self._clock.now().timestamp())
        self._validar_tiempos(payload, now)

        sub, rol, jti = payload.get("sub"), payload.get("rol"), payload.get("jti")
        iat, exp = payload.get("iat"), payload.get("exp")
        if (
            not is_object_id(sub)
            or not isinstance(rol, str)
            or rol == ""
            or not isinstance(jti, str)
            or jti == ""
            or not _es_int_php(iat)
            or not _es_int_php(exp)
        ):
            raise InvalidTokenError("Token con claims incompletos.")
        assert isinstance(sub, str) and isinstance(iat, int) and isinstance(exp, int)  # noqa: S101
        if exp - iat > MAX_JWT_TTL_SECONDS:
            raise InvalidTokenError("Token con duración superior a la permitida.")

        return TokenClaims(sub, rol, jti, iat, exp)

    @staticmethod
    def _validar_tiempos(payload: dict[str, Any], now: int) -> None:
        """Mismas reglas que `JWT::decode()` de firebase/php-jwt (sin margen de tolerancia)."""
        nbf, iat, exp = payload.get("nbf"), payload.get("iat"), payload.get("exp")
        for valor in (nbf, iat, exp):
            if valor is not None and not _es_numero(valor):
                raise InvalidTokenError("Token con tiempos no numéricos.")
        if nbf is not None and math.floor(nbf) > now:
            raise InvalidTokenError("Token todavía no válido.")
        if nbf is None and iat is not None and math.floor(iat) > now:
            raise InvalidTokenError("Token emitido en el futuro.")
        if exp is not None and now >= exp:
            raise InvalidTokenError("Token expirado.")


def _es_numero(valor: Any) -> bool:
    return isinstance(valor, int | float) and not isinstance(valor, bool) and math.isfinite(valor)


def _es_int_php(valor: Any) -> bool:
    """`is_int()` de PHP tras json_decode: enteros de 64 bits (no bool ni float)."""
    return (
        isinstance(valor, int) and not isinstance(valor, bool) and _INT64_MIN <= valor <= _INT64_MAX
    )


def generate_token(num_bytes: int = 32) -> str:
    """Token hexadecimal aleatorio (64 caracteres por defecto), como `TokenGenerator::generate`."""
    return secrets.token_hex(max(16, num_bytes))


def hash_token(token: str) -> str:
    """Hash SHA-256 en hex (minúsculas) que se guarda en MongoDB en lugar del token en claro."""
    return hashlib.sha256(token.encode("utf-8")).hexdigest()
