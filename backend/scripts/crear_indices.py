"""Crea los índices oficiales (spec 8.4 y 10.13.5). Idempotente: se puede ejecutar varias veces.

Réplica de `scripts/crear_indices.php` del backend PHP: mismos índices, nombres y opciones, así
que da igual cuál de los dos scripts se ejecute (y en qué orden).

Uso (desde la raíz del proyecto):
    python -m scripts.crear_indices [--test]

`--test` usa MONGO_DB_NAME_TEST en lugar de MONGO_DB_NAME.
"""

import json
import sys
from typing import Any

from pymongo.errors import PyMongoError

from app.config import ConfigError, load_settings
from app.database import Collections, Mongo

# (colección, claves, opciones). Las claves van en el mismo orden que en PHP.
INDICES: list[tuple[str, dict[str, int], dict[str, Any]]] = [
    (Collections.CREDENCIALES, {"correo": 1}, {"unique": True, "name": "correo_unico"}),
    (Collections.USUARIOS, {"credencial_id": 1}, {"unique": True, "name": "credencial_id_unico"}),
    (Collections.DOCUMENTOS, {"categoria": 1, "estado": 1}, {"name": "categoria_estado"}),
    (Collections.INSCRIPCIONES, {"estado_solicitud": 1}, {"name": "estado_solicitud"}),
    (Collections.SESIONES, {"token": 1}, {"name": "token"}),
    # TTL: Atlas borra las sesiones cuando vence fecha_expiracion (sustituye la limpieza por cron).
    (
        Collections.SESIONES,
        {"fecha_expiracion": 1},
        {"expireAfterSeconds": 0, "name": "fecha_expiracion_ttl"},
    ),
]


def crear_indices(mongo: Mongo) -> int:
    """Crea cada índice y devuelve el número de errores (sigue con los demás si uno falla)."""
    errores = 0
    for coleccion, claves, opciones in INDICES:
        try:
            nombre = mongo.collection(coleccion).create_index(list(claves.items()), **opciones)
            print(f"  OK  {coleccion}.{nombre}")
        except PyMongoError as exc:
            errores += 1
            claves_json = json.dumps(claves, separators=(",", ":"))
            print(f"  ERROR {coleccion} {claves_json}: {exc}", file=sys.stderr)
    return errores


def main(argv: list[str]) -> None:
    try:
        settings = load_settings()
    except ConfigError as exc:
        print(f"Error de configuración: {exc}", file=sys.stderr)
        raise SystemExit(1) from None

    usar_test = "--test" in argv
    db_name = settings.mongo_db_name_test if usar_test else settings.mongo_db_name
    if usar_test and db_name == settings.mongo_db_name:
        print("MONGO_DB_NAME_TEST no puede ser igual a MONGO_DB_NAME.", file=sys.stderr)
        raise SystemExit(1)

    mongo = Mongo(settings.mongo_uri, db_name)
    print(f"Base de datos: {db_name}")
    try:
        errores = crear_indices(mongo)
    finally:
        mongo.close()

    print("Índices listos." if errores == 0 else f"Terminado con {errores} error(es).")
    raise SystemExit(0 if errores == 0 else 1)


if __name__ == "__main__":
    main(sys.argv[1:])
