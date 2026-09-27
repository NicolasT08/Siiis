from datetime import UTC, datetime, timedelta, timezone

from bson import ObjectId

from app.serializers import is_object_id, serialize, to_object_id

OID = "650f1c2e8b3a4a0012345678"


def test_convierte_tipos_bson_y_renombra_id() -> None:
    oid = ObjectId(OID)
    fecha = datetime(2026, 9, 23, 15, 0, 0, tzinfo=UTC)

    result = serialize(
        {
            "_id": oid,
            "usuario_id": oid,
            "fecha": fecha,
            "lista": [{"_id": oid}],
            "activo": True,
            "orden": 3,
            "nada": None,
        }
    )

    assert result == {
        "id": OID,
        "usuario_id": OID,
        "fecha": "2026-09-23T15:00:00Z",
        "lista": [{"id": OID}],
        "activo": True,
        "orden": 3,
        "nada": None,
    }


def test_elimina_campos_sensibles_en_cualquier_nivel() -> None:
    result = serialize(
        {
            "correo": "a@b.co",
            "password": "$2y$10$hash",
            "token": "abc",
            "token_recuperacion": "def",
            "token_expiracion": datetime.now(UTC),
            "foto_perfil": {"url": "https://res.cloudinary.com/x.jpg", "public_id": "x"},
        }
    )

    assert result == {
        "correo": "a@b.co",
        "foto_perfil": {"url": "https://res.cloudinary.com/x.jpg"},
    }


def test_formatea_fechas_en_utc() -> None:
    bogota = timezone(timedelta(hours=-5))

    assert serialize(datetime(2026, 9, 23, 10, 0, 0, tzinfo=bogota)) == "2026-09-23T15:00:00Z"
    # Sin zona horaria se interpreta como UTC (así las entrega PyMongo sin tz_aware).
    assert serialize(datetime(2026, 9, 23, 15, 0, 0, 999000)) == "2026-09-23T15:00:00Z"


def test_object_id_validos_e_invalidos() -> None:
    assert is_object_id(OID)
    assert is_object_id(OID.upper())
    assert to_object_id(OID) == ObjectId(OID)

    for valor in ["", "xyz", OID[:-1], OID + "9", OID + "\n", {"$ne": 1}, 123, None]:
        assert not is_object_id(valor)
        assert to_object_id(valor) is None
