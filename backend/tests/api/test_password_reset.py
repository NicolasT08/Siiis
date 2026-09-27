from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient

from app.security import PasswordHasher, hash_token
from tests.api.auth_env import CORREO, PASSWORD, AuthEnv, assert_error, bearer
from tests.fakes import FakeMailService

MENSAJE_FORGOT = "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña."
MENSAJE_RESET = "Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña."


@pytest.fixture
def env() -> AuthEnv:
    return AuthEnv()


def forgot(client: TestClient, correo: Any) -> httpx.Response:
    response: httpx.Response = client.post("/api/v1/auth/forgot-password", json={"correo": correo})
    return response


def reset(client: TestClient, token: Any, password: Any) -> httpx.Response:
    response: httpx.Response = client.post(
        "/api/v1/auth/reset-password", json={"token": token, "password": password}
    )
    return response


# --- forgot-password ---------------------------------------------------------------------------


def test_forgot_responde_igual_exista_o_no_el_correo(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()

    existe = forgot(client, CORREO)
    no_existe = forgot(client, "nadie@uptc.edu.co")

    assert existe.status_code == no_existe.status_code == 200
    assert existe.content == no_existe.content
    assert existe.json() == {"mensaje": MENSAJE_FORGOT}
    assert len(env.mail.enviados) == 1
    assert env.mail.enviados[0]["to"] == CORREO
    assert env.mail.enviados[0]["subject"] == "Restablece tu contraseña — Semillero SIIIS"


def test_forgot_guarda_solo_el_hash_del_token_con_expiracion(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)

    forgot(env.client(password_reset_ttl_minutes=30), " ANA@uptc.edu.co ")

    token = env.mail.ultimo_token()
    credencial = env.db.credenciales[str(cred_id)]
    assert credencial["token_recuperacion"] == hash_token(token)
    assert credencial["token_recuperacion"] != token
    assert (credencial["token_expiracion"] - env.clock.now()).total_seconds() == 1800
    assert f"http://localhost:5173/restablecer?token={token}" in env.mail.enviados[0]["html"]


def test_forgot_contenido_del_correo_igual_que_php(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, usuario={"nombre": "Ana <Luisa> & 'Co'"})

    forgot(env.client(), CORREO)

    token = env.mail.ultimo_token()
    enlace = f"http://localhost:5173/restablecer?token={token}"
    assert env.mail.enviados[0]["html"] == (
        "<p>Hola, Ana &lt;Luisa&gt; &amp; &apos;Co&apos;:</p>\n"
        "<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta del Semillero"
        " SIIIS.</p>\n"
        f'<p><a href="{enlace}">Restablecer contraseña</a></p>\n'
        "<p>El enlace vence en 30 minutos y solo puede usarse una vez.</p>\n"
        "<p>Si no solicitaste este cambio, ignora este correo; tu contraseña no cambiará.</p>"
    )
    assert env.mail.enviados[0]["text"] == (
        f"Para restablecer tu contraseña del Semillero SIIIS abre este enlace:\n{enlace}\n\n"
        "Vence en 30 minutos. Si no lo solicitaste, ignora este correo."
    )


def test_forgot_enlace_con_url_que_ya_tiene_query(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD, usuario={"nombre": None})

    forgot(env.client(password_reset_url="https://siiis.com.co/r?x=1"), CORREO)

    token = env.mail.ultimo_token()
    assert f"https://siiis.com.co/r?x=1&amp;token={token}" in env.mail.enviados[0]["html"]
    assert env.mail.enviados[0]["html"].startswith("<p>Hola:</p>")


def test_forgot_no_envia_a_cuentas_no_activas(env: AuthEnv) -> None:
    env.db.crear_cuenta("bloqueada@uptc.edu.co", PASSWORD, {"estado_cuenta": "bloqueada"})
    env.db.crear_cuenta(
        "pendiente@uptc.edu.co", PASSWORD, {"estado_cuenta": "pendiente_verificacion"}
    )
    env.db.crear_cuenta("inactivo@uptc.edu.co", PASSWORD, usuario={"estado": False})
    client = env.client()

    for correo in ["bloqueada@uptc.edu.co", "pendiente@uptc.edu.co", "inactivo@uptc.edu.co"]:
        assert forgot(client, correo).json() == {"mensaje": MENSAJE_FORGOT}
    assert env.mail.enviados == []
    assert all(c["token_recuperacion"] is None for c in env.db.credenciales.values())


def test_forgot_responde_generico_aunque_falle_el_smtp(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    env.mail = FakeMailService(fallar=True)

    response = forgot(env.client(), CORREO)

    assert response.status_code == 200
    assert response.json() == {"mensaje": MENSAJE_FORGOT}
    nivel, mensaje, contexto = env.logger.records[0]
    assert (nivel, mensaje) == ("ERROR", "No se pudo enviar el correo de recuperación")
    assert contexto["error"] == "SMTP caído (simulado)"


def test_forgot_correo_invalido_400(env: AuthEnv) -> None:
    error = assert_error(forgot(env.client(), "no-es-correo"), 400, "VALIDATION_ERROR")
    assert error["fields"] == {"correo": "El correo no tiene un formato válido."}


def test_forgot_con_objeto_es_rechazado(env: AuthEnv) -> None:
    error = assert_error(forgot(env.client(), {"$ne": ""}), 400, "VALIDATION_ERROR")
    assert error["fields"] == {"correo": "Este campo debe ser texto."}
    assert env.credenciales.consultas == []


def test_forgot_rate_limit(env: AuthEnv) -> None:
    client = env.client(rate_limit_forgot_max=1, rate_limit_forgot_window_seconds=3600)
    forgot(client, "a@uptc.edu.co")

    response = forgot(client, "b@uptc.edu.co")
    assert_error(response, 429, "RATE_LIMITED")
    assert response.headers["retry-after"] == "3600"


def test_forgot_y_login_tienen_limites_separados(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client(rate_limit_forgot_max=1, rate_limit_login_max=1)

    forgot(client, CORREO)
    assert env.login(client).status_code == 200


# --- reset-password ----------------------------------------------------------------------------


def test_reset_con_token_valido(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    token_sesion_a = env.token(client)
    token_sesion_b = env.token(client)
    forgot(client, CORREO)

    response = reset(client, env.mail.ultimo_token(), "Nueva-Clave-2026")

    assert response.status_code == 200, response.text
    assert response.json() == {"mensaje": MENSAJE_RESET}
    credencial = env.db.credenciales[str(cred_id)]
    assert PasswordHasher().verify("Nueva-Clave-2026", credencial["password"])
    assert credencial["token_recuperacion"] is None
    assert credencial["token_expiracion"] is None

    # Todas las sesiones previas quedan desactivadas.
    for token in (token_sesion_a, token_sesion_b):
        assert_error(client.get("/api/v1/auth/me", headers=bearer(token)), 401, "UNAUTHORIZED")

    # Login con la contraseña vieja falla y con la nueva funciona.
    assert_error(env.login(client), 401, "INVALID_CREDENTIALS")
    assert env.login(client, CORREO, "Nueva-Clave-2026").status_code == 200


def test_reset_token_es_de_un_solo_uso(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    forgot(client, CORREO)
    token = env.mail.ultimo_token()

    assert reset(client, token, "Nueva-Clave-2026").status_code == 200
    assert_error(reset(client, token, "Otra-Clave-2026"), 400, "INVALID_OR_EXPIRED_TOKEN")


def test_reset_nuevo_forgot_invalida_el_token_anterior(env: AuthEnv) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    forgot(client, CORREO)
    viejo = env.mail.ultimo_token()
    forgot(client, CORREO)

    assert_error(reset(client, viejo, "Nueva-Clave-2026"), 400, "INVALID_OR_EXPIRED_TOKEN")
    assert reset(client, env.mail.ultimo_token(), "Nueva-Clave-2026").status_code == 200


@pytest.mark.parametrize("token", ["a" * 64, "corto", "A" * 64, "g" * 64, "a" * 65])
def test_reset_con_token_invalido(env: AuthEnv, token: str) -> None:
    env.db.crear_cuenta(CORREO, PASSWORD)

    error = assert_error(
        reset(env.client(), token, "Nueva-Clave-2026"), 400, "INVALID_OR_EXPIRED_TOKEN"
    )
    assert error == {
        "code": "INVALID_OR_EXPIRED_TOKEN",
        "message": "El enlace de recuperación no es válido o ya venció.",
    }


def test_reset_con_token_expirado(env: AuthEnv) -> None:
    cred_id, _ = env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client(password_reset_ttl_minutes=30)
    forgot(client, CORREO)

    env.clock.advance(30 * 60 - 1)
    token = env.mail.ultimo_token()
    env.clock.advance(1)

    assert_error(reset(client, token, "Nueva-Clave-2026"), 400, "INVALID_OR_EXPIRED_TOKEN")
    assert PasswordHasher().verify(PASSWORD, env.db.credenciales[str(cred_id)]["password"])


def test_reset_valida_la_nueva_contrasena(env: AuthEnv) -> None:
    client = env.client(password_min_length=8)

    corta = assert_error(reset(client, "a" * 64, "corta"), 400, "VALIDATION_ERROR")
    assert corta["fields"] == {"password": "La contraseña debe tener al menos 8 caracteres."}

    larga = assert_error(reset(client, "a" * 64, "x" * 73), 400, "VALIDATION_ERROR")
    assert larga["fields"] == {"password": "La contraseña es demasiado larga (máximo 72 bytes)."}


def test_reset_con_objetos_es_rechazado(env: AuthEnv) -> None:
    error = assert_error(
        reset(env.client(), {"$ne": ""}, "Nueva-Clave-2026"), 400, "VALIDATION_ERROR"
    )
    assert error["fields"] == {"token": "Este campo debe ser texto."}


def test_reset_con_nul_responde_500_como_php(env: AuthEnv) -> None:
    """Paridad provisional con PHP (DEC-P09): se corregirá en ambos backends tras la fase 1."""
    env.db.crear_cuenta(CORREO, PASSWORD)
    client = env.client()
    forgot(client, CORREO)

    response = reset(client, env.mail.ultimo_token(), "Nueva\u0000Clave-2026")

    assert_error(response, 500, "INTERNAL_ERROR")
