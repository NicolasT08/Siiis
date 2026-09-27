"""Documentos de MongoDB → datos JSON seguros (réplica de `Support/Serializer` de PHP).

ObjectId → string, fechas → ISO 8601 UTC, `_id` → `id`, y elimina campos sensibles.
"""

import re
from datetime import UTC, datetime
from typing import Any

from bson import ObjectId

SENSITIVE_FIELDS = frozenset(
    {"password", "token", "token_recuperacion", "token_expiracion", "public_id"}
)

_OBJECT_ID_RE = re.compile(r"[a-f0-9]{24}", re.IGNORECASE | re.ASCII)


def serialize(value: Any) -> Any:
    if isinstance(value, ObjectId):
        return str(value)
    if isinstance(value, datetime):
        return format_date(value)
    if isinstance(value, dict):
        return {
            ("id" if key == "_id" else key): serialize(item)
            for key, item in value.items()
            if not (isinstance(key, str) and key in SENSITIVE_FIELDS)
        }
    if isinstance(value, list | tuple):
        return [serialize(item) for item in value]
    return value


def format_date(value: datetime) -> str:
    # PyMongo entrega fechas en UTC; una fecha sin zona se interpreta como UTC.
    if value.tzinfo is None:
        value = value.replace(tzinfo=UTC)
    return value.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def is_object_id(value: Any) -> bool:
    return isinstance(value, str) and _OBJECT_ID_RE.fullmatch(value) is not None


def to_object_id(value: Any) -> ObjectId | None:
    """Devuelve el ObjectId o None si el valor no es un identificador válido (nunca lanza)."""
    if isinstance(value, ObjectId):
        return value
    return ObjectId(value) if is_object_id(value) else None
