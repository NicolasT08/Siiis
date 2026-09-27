import jwt as pyjwt
import pytest

from tests.api.auth_env import CORREO, PASSWORD, AuthEnv, assert_error, bearer
from tests.support import JWT_SECRET


@pytest.fixture
def env() -> AuthEnv:
    return AuthEnv()


def test_me_con_token_valido(env: AuthEnv) -> None:
    _, user_id = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()

    response = client.get("/api/v1/auth/me", headers=bearer(env.token(client)))

    assert response.status_code == 200, response.text
    assert response.headers["cache-control"] == "no-store"
    assert response.json() == {
        "usuario": {
            "id": str(user_id),
            "nombre": "Ana",
            "apellido": "Pérez",
            "rol": "superadministrador",
            "foto_perfil": {"url": "https://res.cloudinary.com/demo/image/upload/ana.jpg"},
        }
    }


def test_me_sin_token(env: AuthEnv) -> None:
    error = assert_error(env.client().get("/api/v1/auth/me"), 401, "UNAUTHORIZED")
    assert error == {
        "code": "UNAUTHORIZED",
        "message": "Autenticación requerida o sesión no válida.",
    }


@pytest.mark.parametrize("cabecera", ["Basic abc", "Bearer", "Bearer ", "Bearer a b", "abc"])
def test_me_con_cabecera_mal_formada(env: AuthEnv, cabecera: str) -> None:
    response = env.client().get("/api/v1/auth/me", headers={"Authorization": cabecera})

    assert_error(response, 401, "UNAUTHORIZED")


def test_me_acepta_bearer_sin_distinguir_mayusculas(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token = env.token(client)

    response = client.get("/api/v1/auth/me", headers={"Authorization": f"  bearer   {token} "})

    assert response.status_code == 200


def test_me_con_token_basura(env: AuthEnv) -> None:
    response = env.client().get("/api/v1/auth/me", headers=bearer("abc.def.ghi"))

    assert_error(response, 401, "UNAUTHORIZED")


def test_me_con_sesion_desactivada(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token = env.token(client)

    for sesion in env.db.sesiones.values():
        sesion["activa"] = False

    assert_error(client.get("/api/v1/auth/me", headers=bearer(token)), 401, "UNAUTHORIZED")


def test_me_con_token_expirado(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token = env.token(client)

    env.clock.advance(28801)

    assert_error(client.get("/api/v1/auth/me", headers=bearer(token)), 401, "UNAUTHORIZED")


def test_me_con_token_valido_sin_sesion_registrada(env: AuthEnv) -> None:
    _, user_id = env.db.crear_cuenta(CORREO, PASSWORD)
    now = int(env.clock.now().timestamp())
    token = pyjwt.encode(
        {
            "sub": str(user_id),
            "rol": "superadministrador",
            "jti": "inventado",
            "iat": now,
            "exp": now + 60,
        },
        JWT_SECRET,
        algorithm="HS256",
    )

    assert_error(env.client().get("/api/v1/auth/me", headers=bearer(token)), 401, "UNAUTHORIZED")


def test_me_con_sesion_de_otro_usuario(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    _, otro_id = env.db.crear_cuenta("otro@uptc.edu.co", PASSWORD)
    client = env.client()
    token = env.token(client)
    jti = pyjwt.decode(token, options={"verify_signature": False})["jti"]
    now = int(env.clock.now().timestamp())
    falso = pyjwt.encode(
        {"sub": str(otro_id), "rol": "x", "jti": jti, "iat": now, "exp": now + 60},
        JWT_SECRET,
        algorithm="HS256",
    )

    assert_error(client.get("/api/v1/auth/me", headers=bearer(falso)), 401, "UNAUTHORIZED")


def test_me_con_usuario_desactivado_despues_del_login(env: AuthEnv) -> None:
    _, user_id = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token = env.token(client)

    env.db.usuarios[str(user_id)]["estado"] = False

    assert_error(client.get("/api/v1/auth/me", headers=bearer(token)), 401, "UNAUTHORIZED")


def test_logout_invalida_la_sesion(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    headers = bearer(env.token(client))

    logout = client.post("/api/v1/auth/logout", headers=headers)
    assert logout.status_code == 204
    assert logout.content == b""
    assert "content-type" not in logout.headers
    assert next(iter(env.db.sesiones.values()))["activa"] is False

    assert_error(client.get("/api/v1/auth/me", headers=headers), 401, "UNAUTHORIZED")
    assert_error(client.post("/api/v1/auth/logout", headers=headers), 401, "UNAUTHORIZED")


def test_logout_solo_cierra_la_sesion_actual(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token_a = env.token(client)
    token_b = env.token(client)

    client.post("/api/v1/auth/logout", headers=bearer(token_a))

    assert client.get("/api/v1/auth/me", headers=bearer(token_b)).status_code == 200


def test_logout_sin_token(env: AuthEnv) -> None:
    assert_error(env.client().post("/api/v1/auth/logout"), 401, "UNAUTHORIZED")


def test_json_invalido_tiene_prioridad_sobre_auth(env: AuthEnv) -> None:
    response = env.client().post(
        "/api/v1/auth/logout", content=b"{", headers={"Content-Type": "application/json"}
    )

    assert_error(response, 400, "INVALID_JSON")


def test_login_con_google_no_esta_registrado(env: AuthEnv) -> None:
    response = env.client().post("/api/v1/auth/google", json={"id_token": "x"})

    assert_error(response, 404, "NOT_FOUND")


def test_metodo_no_permitido_en_auth(env: AuthEnv) -> None:
    response = env.client().get("/api/v1/auth/login")

    assert_error(response, 405, "METHOD_NOT_ALLOWED")
    assert response.headers["allow"] == "POST"
