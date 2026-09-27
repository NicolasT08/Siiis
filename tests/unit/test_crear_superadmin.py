from datetime import UTC, datetime

from bson import ObjectId

from scripts.crear_superadmin import construir_documentos, validar


def test_validar_como_el_script_php() -> None:
    assert validar("ana@uptc.edu.co", "Ana", "Pérez", "Clave-Segura-2026", 8) is None
    assert validar("no-es-correo", "Ana", "Pérez", "Clave-Segura-2026", 8) == "correo no válido."
    assert validar("a@b.co", "", "Pérez", "Clave-Segura-2026", 8) == (
        "nombre y apellido son obligatorios."
    )
    assert validar("a@b.co", "Ana", "Pérez", "corta", 8) == (
        "la contraseña debe tener al menos 8 caracteres."
    )
    assert validar("a@b.co", "Ana", "Pérez", "ñ" * 37, 8) == (
        "la contraseña no puede superar 72 bytes (límite de bcrypt)."
    )


def test_documentos_con_los_campos_del_script_php() -> None:
    cred_id, usuario_id, ahora = ObjectId(), ObjectId(), datetime.now(UTC)

    credencial, usuario = construir_documentos(
        cred_id, usuario_id, "ana@uptc.edu.co", "$2b$10$hash", "Ana", "Pérez", ahora
    )

    assert list(credencial) == [
        "_id",
        "correo",
        "password",
        "usuario_id",
        "estado_cuenta",
        "token_recuperacion",
        "token_expiracion",
        "intentos_fallidos",
        "fecha_creacion",
        "ultimo_acceso",
    ]
    assert credencial["usuario_id"] == usuario_id
    assert credencial["estado_cuenta"] == "activa"
    assert list(usuario) == [
        "_id",
        "credencial_id",
        "nombre",
        "apellido",
        "rol",
        "programa_academico",
        "semestre",
        "foto_perfil",
        "biografia",
        "telefono",
        "estado",
        "fecha_ingreso",
        "fecha_salida",
    ]
    assert usuario["credencial_id"] == cred_id
    assert usuario["rol"] == "superadministrador"
    assert usuario["estado"] is True
