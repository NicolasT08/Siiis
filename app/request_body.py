"""Lectura del cuerpo de la petición, réplica de `JsonBodyMiddleware` de PHP.

- `application/json`: JSON mal formado → 400 INVALID_JSON; si no es un objeto `{...}`, también.
  Cuerpo vacío → objeto vacío. Mismas reglas que `json_decode($raw, true, 32)` de PHP.
- `application/x-www-form-urlencoded` en POST: PHP lo expone como `$_POST`; se replica lo básico
  (`campo[...]` llega como arreglo, es decir, "no es texto").
- Cualquier otro caso: sin cuerpo (None).

Se ejecuta como dependencia del router `/api/v1`: después del enrutamiento (404/405 tienen
prioridad) y antes de la autenticación y de la lógica de cada ruta, igual que en Slim.
"""

import json
from typing import Any
from urllib.parse import parse_qsl

from fastapi import Request

from app.errors import ApiError

INVALID_JSON_MESSAGE = "El cuerpo de la petición no es un JSON válido."
NOT_OBJECT_MESSAGE = "El cuerpo de la petición debe ser un objeto JSON."

# json_decode(..., depth: 32) de PHP admite como máximo 31 niveles de objetos/arreglos.
MAX_JSON_NESTING = 31
_PHP_TRIM_BYTES = b" \t\n\r\0\x0b"


def _rechazar_constante(nombre: str) -> Any:
    raise ValueError(f"Constante JSON no permitida: {nombre}")


def _estructura_valida(valor: Any) -> bool:
    """Profundidad máxima de PHP y sin surrogates UTF-16 sueltos (PHP los rechaza)."""
    pendientes: list[tuple[Any, int]] = [(valor, 0)]
    while pendientes:
        actual, nivel = pendientes.pop()
        if isinstance(actual, dict | list):
            if nivel + 1 > MAX_JSON_NESTING:
                return False
            if isinstance(actual, dict):
                for clave, item in actual.items():
                    if _tiene_surrogate(clave):
                        return False
                    pendientes.append((item, nivel + 1))
            else:
                pendientes.extend((item, nivel + 1) for item in actual)
        elif isinstance(actual, str) and _tiene_surrogate(actual):
            return False
    return True


def _tiene_surrogate(texto: str) -> bool:
    return any("\ud800" <= caracter <= "\udfff" for caracter in texto)


def parse_json_body(raw: bytes) -> dict[str, Any]:
    raw = raw.strip(_PHP_TRIM_BYTES)
    if raw == b"":
        return {}

    try:
        decoded = json.loads(raw.decode("utf-8"), parse_constant=_rechazar_constante)
    except (ValueError, RecursionError):
        # UnicodeDecodeError y JSONDecodeError son subclases de ValueError.
        raise ApiError(400, "INVALID_JSON", INVALID_JSON_MESSAGE) from None
    if not _estructura_valida(decoded):
        raise ApiError(400, "INVALID_JSON", INVALID_JSON_MESSAGE)

    # Solo se aceptan objetos JSON ({}), no listas ni escalares.
    if not isinstance(decoded, dict) or not raw.startswith(b"{"):
        raise ApiError(400, "INVALID_JSON", NOT_OBJECT_MESSAGE)
    return decoded


def parse_form_body(raw: bytes) -> dict[str, Any]:
    datos: dict[str, Any] = {}
    for clave, valor in parse_qsl(raw.decode("utf-8", errors="replace"), keep_blank_values=True):
        clave = clave.lstrip(" ")
        corchete = clave.find("[")
        if corchete > 0 and "]" in clave[corchete:]:
            # PHP convierte `campo[...]` en un arreglo.
            datos[clave[:corchete].replace(" ", "_").replace(".", "_")] = {}
            continue
        datos[clave.replace(" ", "_").replace(".", "_").replace("[", "_")] = valor
    return datos


async def parse_body(request: Request) -> None:
    content_type = request.headers.get("content-type", "").lower()
    body: Any = None
    if "application/json" in content_type:
        body = parse_json_body(await request.body())
    elif (
        request.method == "POST"
        and content_type.split(";")[0].strip() == "application/x-www-form-urlencoded"
    ):
        body = parse_form_body(await request.body())
    request.state.parsed_body = body


def parsed_body(request: Request) -> Any:
    return getattr(request.state, "parsed_body", None)
