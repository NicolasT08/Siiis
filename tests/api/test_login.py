import base64
import json
import re
from typing import Any

import pytest

from app.security import PasswordHasher, hash_token
from tests.api.auth_env import CORREO, IP, PASSWORD, AuthEnv, assert_error


@pytest.fixture
def env() -> AuthEnv:
    return AuthEnv()


def test_login_exitoso(env: AuthEnv) -> None:
    cred_id, user_id = env.db.crear_cuenta(CORREO, PASSWORD, {"intentos_fallidos": 2})

    response = env.login(env.client(), "  ANA@uptc.edu.co ", PASSWORD)

    assert response.status_code == 200, response.text
    assert response.headers["cache-control"] == "no-store"
    body = response.json()
    assert list(body) == ["token", "expira_en", "usuario"]
    assert body["expira_en"] == 28800
    assert body["usuario"] == {
        "id": str(user_id),
        "nombre": "Ana",
        "apellido": "Pérez",
        "rol": "superadministrador",
        "foto_perfil": {"url": "https://res.cloudinary.com/demo/image/upload/ana.jpg"},
    }
    assert list(body["usuario"]) == ["id", "nombre", "apellido", "rol", "foto_perfil"]

    # Sesión registrada con el hash del jti, nunca el JWT.
    assert len(env.db.sesiones) == 1
    sesion = next(iter(env.db.sesiones.values()))
    assert list(sesion)[1:] == [
        "usuario_id",
        "token",
        "fecha_inicio",
        "fecha_expiracion",
        "ip_acceso",
        "activa",
    ]
    assert sesion["usuario_id"] == user_id
    assert sesion["activa"] is True
    assert sesion["ip_acceso"] == IP
    assert re.fullmatch(r"[a-f0-9]{64}", sesion["token"])
    assert sesion["token"] not in body["token"]
    assert sesion["fecha_inicio"] == env.clock.now()
    assert (sesion["fecha_expiracion"] - sesion["fecha_inicio"]).total_seconds() == 28800

    # Contador reiniciado y último acceso actualizado.
    credencial = env.db.credenciales[str(cred_id)]
    assert credencial["intentos_fallidos"] == 0
    assert credencial["ultimo_acceso"] == env.clock.now()


def test_foto_perfil_null_si_no_hay_foto(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, usuario={"foto_perfil": None})

    assert env.login(env.client()).json()["usuario"]["foto_perfil"] is None


def test_respuesta_no_expone_secretos(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    raw = env.login(env.client()).text

    for prohibido in ["password", "token_recuperacion", "public_id", "$2b$", "credencial_id"]:
        assert prohibido not in raw


def test_rehash_cuando_el_hash_es_viejo(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)
    viejo = env.db.credenciales[str(cred_id)]["password"]

    env.login(env.client())

    nuevo = env.db.credenciales[str(cred_id)]["password"]
    assert nuevo != viejo
    assert nuevo.startswith("$2b$10$")
    assert PasswordHasher().verify(PASSWORD, nuevo)


def test_no_rehashea_un_hash_2y_de_php_vigente(env: AuthEnv) -> None:
    hash_php = "$2y$10$uG5hgec7.WoC4.sm5tJhOenBh2htvFElAY0nbamKFMQx.TU7IukBm"  # 'Prueba123!'
    cred_id, _ = env.db.crear_cuenta(CORREO, "x", {"password": hash_php})

    assert env.login(env.client(), CORREO, "Prueba123!").status_code == 200
    assert env.db.credenciales[str(cred_id)]["password"] == hash_php


def test_correo_inexistente_devuelve_401_generico(env: AuthEnv) -> None:
    error = assert_error(
        env.login(env.client(), "nadie@uptc.edu.co", "loquesea"), 401, "INVALID_CREDENTIALS"
    )
    assert error == {"code": "INVALID_CREDENTIALS", "message": "Correo o contraseña incorrectos."}


def test_password_incorrecta_devuelve_mismo_401_e_incrementa_contador(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()

    inexistente = env.login(client, "nadie@uptc.edu.co", "x")
    incorrecta = env.login(client, CORREO, "incorrecta")

    assert_error(incorrecta, 401, "INVALID_CREDENTIALS")
    assert inexistente.content == incorrecta.content
    assert env.db.credenciales[str(cred_id)]["intentos_fallidos"] == 1
    assert env.db.sesiones == {}


def test_no_bloquea_al_superar_el_umbral(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client(max_login_attempts=2)

    for _ in range(3):
        env.login(client, CORREO, "incorrecta")

    assert env.db.credenciales[str(cred_id)]["intentos_fallidos"] == 3
    assert env.db.credenciales[str(cred_id)]["estado_cuenta"] == "activa"
    assert [r[1] for r in env.logger.records].count("Umbral de intentos fallidos alcanzado") == 2
    assert env.login(client).status_code == 200


@pytest.mark.parametrize(
    ("estado", "codigo", "mensaje"),
    [
        ("bloqueada", "ACCOUNT_BLOCKED", "La cuenta está bloqueada."),
        ("pendiente_verificacion", "ACCOUNT_PENDING", "La cuenta está pendiente de verificación."),
        ("otro", "ACCOUNT_BLOCKED", "La cuenta está bloqueada."),
    ],
)
def test_cuenta_no_activa_devuelve_403(
    env: AuthEnv, estado: str, codigo: str, mensaje: str
) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, {"estado_cuenta": estado})

    error = assert_error(env.login(env.client()), 403, codigo)
    assert error["message"] == mensaje
    assert env.db.sesiones == {}


def test_cuenta_bloqueada_con_password_incorrecta_no_revela_el_estado(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, {"estado_cuenta": "bloqueada"})

    assert_error(env.login(env.client(), CORREO, "incorrecta"), 401, "INVALID_CREDENTIALS")


def test_usuario_inactivo_devuelve_403(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, usuario={"estado": False})

    error = assert_error(env.login(env.client()), 403, "ACCOUNT_INACTIVE")
    assert error["message"] == "El usuario está inactivo."
    assert env.db.sesiones == {}


def test_credencial_sin_usuario_devuelve_401_y_se_registra(env: AuthEnv) -> None:
    _, user_id = env.db.crear_cuenta(CORREO, PASSWORD)
    del env.db.usuarios[str(user_id)]

    assert_error(env.login(env.client()), 401, "INVALID_CREDENTIALS")
    assert env.logger.records[0][:2] == ("ERROR", "Credencial sin perfil de Usuarios válido")


@pytest.mark.parametrize(
    ("payload", "campos"),
    [
        ({}, ["correo", "password"]),
        ({"correo": "no-es-correo", "password": "x"}, ["correo"]),
        ({"correo": "a@b.co", "password": ""}, ["password"]),
        ({"correo": "a@b.co", "password": None}, ["password"]),
        ({"correo": "a@b.co", "password": True}, ["password"]),
    ],
)
def test_payload_invalido_devuelve_400(env: AuthEnv, payload: Any, campos: list[str]) -> None:
    response = env.client().post("/api/v1/auth/login", json=payload)

    error = assert_error(response, 400, "VALIDATION_ERROR")
    assert error["message"] == "Los datos enviados no son válidos."
    assert list(error["fields"]) == campos


def test_formato_exacto_de_validation_error(env: AuthEnv) -> None:
    response = env.client().post("/api/v1/auth/login", json={"correo": "x"})

    assert (
        response.content
        == (
            '{"error":{"code":"VALIDATION_ERROR","message":"Los datos enviados no son válidos.",'
            '"fields":{"correo":"El correo no tiene un formato válido.",'
            '"password":"Este campo es obligatorio."}}}'
        ).encode()
    )


@pytest.mark.parametrize(
    ("payload", "campo"),
    [
        ({"correo": {"$ne": ""}, "password": "x"}, "correo"),
        ({"correo": {"$regex": ".*"}, "password": "x"}, "correo"),
        ({"correo": CORREO, "password": {"$ne": ""}}, "password"),
        ({"correo": [CORREO], "password": "x"}, "correo"),
    ],
)
def test_payload_con_objetos_se_rechaza_sin_consultar(
    env: AuthEnv, payload: dict[str, Any], campo: str
) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    error = assert_error(
        env.client().post("/api/v1/auth/login", json=payload), 400, "VALIDATION_ERROR"
    )
    assert error["fields"][campo] == "Este campo debe ser texto."
    assert env.credenciales.consultas == []


def test_inyeccion_por_formulario_se_rechaza(env: AuthEnv) -> None:
    response = env.client().post(
        "/api/v1/auth/login",
        content=b"correo[$ne]=&password[$ne]=",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )

    error = assert_error(response, 400, "VALIDATION_ERROR")
    assert error["fields"] == {
        "correo": "Este campo debe ser texto.",
        "password": "Este campo debe ser texto.",
    }


def test_json_mal_formado_devuelve_400(env: AuthEnv) -> None:
    response = env.client().post(
        "/api/v1/auth/login", content=b'{"correo":', headers={"Content-Type": "application/json"}
    )

    assert_error(response, 400, "INVALID_JSON")


def test_json_que_no_es_objeto_devuelve_400(env: AuthEnv) -> None:
    response = env.client().post("/api/v1/auth/login", json=["a", "b"])

    assert_error(response, 400, "INVALID_JSON")


def test_rate_limit_devuelve_429_con_retry_after(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client(rate_limit_login_max=2, rate_limit_login_window_seconds=900)

    env.login(client, CORREO, "x")
    env.login(client, CORREO, "x")
    response = env.login(client)

    error = assert_error(response, 429, "RATE_LIMITED")
    assert error["message"] == "Demasiadas solicitudes. Intenta de nuevo más tarde."
    assert response.headers["retry-after"] == "900"

    env.clock.advance(900)
    assert env.login(client).status_code == 200


def test_rate_limit_cuenta_tambien_payloads_invalidos(env: AuthEnv) -> None:
    client = env.client(rate_limit_login_max=1)

    assert_error(client.post("/api/v1/auth/login", json={}), 400, "VALIDATION_ERROR")
    assert_error(client.post("/api/v1/auth/login", json={}), 429, "RATE_LIMITED")


def test_remember_me_se_ignora(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    response = env.client().post(
        "/api/v1/auth/login", json={"correo": CORREO, "password": PASSWORD, "recordarme": True}
    )

    assert response.json()["expira_en"] == 28800


def test_expira_en_usa_jwt_ttl_recortado(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    assert env.login(env.client(jwt_ttl_seconds=3600)).json()["expira_en"] == 3600
    assert env.login(env.client(jwt_ttl_seconds=999999)).json()["expira_en"] == 28800


def test_sesion_guarda_hash_del_jti(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    token = env.token(env.client())
    payload = json.loads(base64.urlsafe_b64decode(token.split(".")[1] + "=="))

    assert set(payload) == {"sub", "rol", "jti", "iat", "exp"}
    sesion = next(iter(env.db.sesiones.values()))
    assert sesion["token"] == hash_token(payload["jti"])
