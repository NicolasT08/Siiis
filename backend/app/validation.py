"""Validación de entradas con los mismos textos y reglas que el backend PHP.

Réplica de `Validation/InputReader`, `LoginValidator`, `ForgotPasswordValidator` y
`ResetPasswordValidator`. Antiinyección NoSQL: solo se aceptan escalares donde se espera texto;
arrays u objetos (p. ej. {"$ne": ""}) se reportan como error del campo.
"""

import re
from dataclasses import dataclass
from typing import Any

from app.errors import ApiError

MSG_OBLIGATORIO = "Este campo es obligatorio."
MSG_TEXTO = "Este campo debe ser texto."
MSG_CORREO = "El correo no tiene un formato válido."
MSG_PASSWORD_LARGA = "La contraseña es demasiado larga."  # noqa: S105
MSG_PASSWORD_72 = "La contraseña es demasiado larga (máximo 72 bytes)."  # noqa: S105

LOGIN_MAX_PASSWORD_LENGTH = 256
RESET_MAX_PASSWORD_BYTES = 72
CORREO_MAX_LENGTH = 254

# Caracteres que elimina trim() de PHP (no todos los espacios Unicode, como str.strip()).
_PHP_TRIM = " \t\n\r\0\x0b"
_INT64_MIN, _INT64_MAX = -(2**63), 2**63 - 1

# Expresión de FILTER_VALIDATE_EMAIL de PHP (ext/filter/logical_filters.c), flags /iD.
_LOCAL_ATOM = r"[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+"
_LOCAL_QUOTED = (
    r"\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22"
)
_LOCAL_PART = rf"(?:(?:{_LOCAL_ATOM})|(?:{_LOCAL_QUOTED}))"
_OCTET = r"(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))"
_PHP_EMAIL_RE = re.compile(
    r"^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})"
    r"(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)"
    rf"{_LOCAL_PART}(?:\.{_LOCAL_PART})*@"
    r"(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}"
    r"(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)"
    r"|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})"
    r"|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::"
    r"(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))"
    r"|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)"
    r"|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::"
    r"(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?"
    rf"{_OCTET}(?:\.{_OCTET}){{3}}))\]))\Z",
    re.IGNORECASE | re.ASCII,
)
_PHP_EMAIL_MAX_LENGTH = 320


def php_email_valido(correo: str) -> bool:
    """`filter_var($correo, FILTER_VALIDATE_EMAIL) !== false` de PHP."""
    return len(correo) <= _PHP_EMAIL_MAX_LENGTH and _PHP_EMAIL_RE.match(correo) is not None


def php_float_str(valor: float) -> str:
    """`(string) $float` de PHP 8 (ini precision=14)."""
    texto = format(valor, ".14G")
    if "E" not in texto:
        return texto
    mantisa, exponente = texto.split("E")
    if "." not in mantisa:
        mantisa += ".0"
    exp = int(exponente)
    return f"{mantisa}E{'+' if exp >= 0 else '-'}{abs(exp)}"


def _php_trim(valor: str) -> str:
    return valor.strip(_PHP_TRIM)


def _escalar_a_texto(valor: Any) -> str | None:
    """Convierte un escalar JSON (string, int, float) a texto como PHP; None si no es escalar."""
    if isinstance(valor, bool):
        return None
    if isinstance(valor, str):
        return valor
    if isinstance(valor, int):
        # json_decode de PHP convierte los enteros fuera de 64 bits en float.
        return str(valor) if _INT64_MIN <= valor <= _INT64_MAX else php_float_str(float(valor))
    if isinstance(valor, float):
        return php_float_str(valor)
    return None


def read_string(body: Any, field: str, errors: dict[str, str], trim: bool = True) -> str:
    if not isinstance(body, dict) or body.get(field) is None:
        errors[field] = MSG_OBLIGATORIO
        return ""

    texto = _escalar_a_texto(body[field])
    if texto is None:
        errors[field] = MSG_TEXTO
        return ""

    if trim:
        texto = _php_trim(texto)
    if texto == "":
        errors[field] = MSG_OBLIGATORIO
    return texto


def read_correo(body: Any, errors: dict[str, str]) -> str:
    """Correo normalizado (sin espacios, minúsculas) y con formato válido."""
    correo = read_string(body, "correo", errors).lower()
    if "correo" in errors:
        return ""
    if len(correo) > CORREO_MAX_LENGTH or not php_email_valido(correo):
        errors["correo"] = MSG_CORREO
    return correo


@dataclass(frozen=True)
class LoginInput:
    correo: str
    password: str


@dataclass(frozen=True)
class ResetPasswordInput:
    token: str
    password: str


def validate_login(body: Any) -> LoginInput:
    """POST /auth/login — {"correo", "password"}. Campos extra (p. ej. "recordarme") se ignoran."""
    errors: dict[str, str] = {}
    correo = read_correo(body, errors)
    password = read_string(body, "password", errors, trim=False)

    if "password" not in errors and len(password) > LOGIN_MAX_PASSWORD_LENGTH:
        errors["password"] = MSG_PASSWORD_LARGA

    if errors:
        raise ApiError.validation(errors)
    return LoginInput(correo, password)


def validate_forgot_password(body: Any) -> str:
    """POST /auth/forgot-password — {"correo"}. Devuelve el correo normalizado."""
    errors: dict[str, str] = {}
    correo = read_correo(body, errors)
    if errors:
        raise ApiError.validation(errors)
    return correo


def validate_reset_password(body: Any, password_min_length: int) -> ResetPasswordInput:
    """POST /auth/reset-password — {"token", "password"}.

    Política: solo longitud mínima configurable (TODO_SPEC, DEC-B11) y máximo 72 bytes.
    """
    errors: dict[str, str] = {}
    token = read_string(body, "token", errors)
    password = read_string(body, "password", errors, trim=False)

    if "password" not in errors:
        if len(password) < password_min_length:
            errors["password"] = (
                f"La contraseña debe tener al menos {password_min_length} caracteres."
            )
        elif len(password.encode("utf-8")) > RESET_MAX_PASSWORD_BYTES:
            errors["password"] = MSG_PASSWORD_72

    if errors:
        raise ApiError.validation(errors)
    return ResetPasswordInput(token, password)
