from typing import Any, cast

import pytest
from pymongo.errors import OperationFailure

from app.database import Mongo
from scripts.crear_indices import INDICES, crear_indices


def test_mismos_indices_que_el_script_php() -> None:
    assert INDICES == [
        ("Credenciales", {"correo": 1}, {"unique": True, "name": "correo_unico"}),
        ("Usuarios", {"credencial_id": 1}, {"unique": True, "name": "credencial_id_unico"}),
        ("Documentos", {"categoria": 1, "estado": 1}, {"name": "categoria_estado"}),
        ("Inscripciones", {"estado_solicitud": 1}, {"name": "estado_solicitud"}),
        ("Sesiones", {"token": 1}, {"name": "token"}),
        (
            "Sesiones",
            {"fecha_expiracion": 1},
            {"expireAfterSeconds": 0, "name": "fecha_expiracion_ttl"},
        ),
    ]
    # El orden de las claves del índice compuesto importa.
    assert list(INDICES[2][1]) == ["categoria", "estado"]


class _Coleccion:
    def __init__(self, nombre: str, llamadas: list[tuple[str, Any, dict[str, Any]]]) -> None:
        self._nombre = nombre
        self._llamadas = llamadas

    def create_index(self, claves: Any, **opciones: Any) -> str:
        self._llamadas.append((self._nombre, claves, opciones))
        if opciones["name"] == "token":
            raise OperationFailure("conflicto")
        return str(opciones["name"])


class _Mongo:
    def __init__(self) -> None:
        self.llamadas: list[tuple[str, Any, dict[str, Any]]] = []

    def collection(self, nombre: str) -> _Coleccion:
        return _Coleccion(nombre, self.llamadas)


def test_sigue_con_los_demas_y_cuenta_errores(capsys: pytest.CaptureFixture[str]) -> None:
    mongo = _Mongo()

    errores = crear_indices(cast(Mongo, mongo))

    assert errores == 1
    assert len(mongo.llamadas) == len(INDICES)
    assert mongo.llamadas[2][1] == [("categoria", 1), ("estado", 1)]
    salida = capsys.readouterr()
    assert "  OK  Credenciales.correo_unico" in salida.out
    assert "  OK  Sesiones.fecha_expiracion_ttl" in salida.out
    assert 'ERROR Sesiones {"token":1}: conflicto' in salida.err
