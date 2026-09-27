import base64
import json
import time

import jwt as pyjwt
import pytest

from app.security import (
    BCRYPT_COST,
    InvalidTokenError,
    JwtService,
    PasswordHasher,
    generate_token,
    hash_token,
)
from tests.support import JWT_SECRET, FixedClock

USER_ID = "650f1c2e8b3a4a0012345678"

# Generado con PHP 8.2: password_hash('Prueba123!', PASSWORD_BCRYPT).
HASH_PHP_2Y = "$2y$10$uG5hgec7.WoC4.sm5tJhOenBh2htvFElAY0nbamKFMQx.TU7IukBm"

# Emitido con firebase/php-jwt (backend PHP), secreto "s" * 40, iat = 2026-09-23T15:00:00Z.
JWT_PHP = (
    "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9."
    "eyJzdWIiOiI2NTBmMWMyZThiM2E0YTAwMTIzNDU2NzgiLCJyb2wiOiJzdXBlcmFkbWluaXN0cmFkb3IiLCJqdGki"
    "OiIwMTIzNDU2Nzg5YWJjZGVmMDEyMzQ1Njc4OWFiY2RlZiIsImlhdCI6MTc5MDE3NTYwMCwiZXhwIjoxNzkwMjA0NDAwfQ."
    "SbIy0e1FGUpq-cbCtAXimTCAbXbmpQJObN16js6gHmE"
)


def b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


# --- Contraseñas -------------------------------------------------------------------------------


def test_hash_es_bcrypt_2b_coste_10_y_verifica() -> None:
    hasher = PasswordHasher()
    hashed = hasher.hash("Contraseña-Segura-1")

    assert hashed.startswith(f"$2b${BCRYPT_COST}$")
    assert hasher.verify("Contraseña-Segura-1", hashed)
    assert not hasher.verify("otra", hashed)
    assert not hasher.needs_rehash(hashed)


def test_verifica_hash_2y_generado_por_php() -> None:
    hasher = PasswordHasher()

    assert hasher.verify("Prueba123!", HASH_PHP_2Y)
    assert not hasher.verify("prueba123!", HASH_PHP_2Y)
    assert not hasher.needs_rehash(HASH_PHP_2Y)


def test_needs_rehash_con_otro_coste_o_formato() -> None:
    hasher = PasswordHasher()

    assert hasher.needs_rehash(HASH_PHP_2Y.replace("$10$", "$04$"))
    assert hasher.needs_rehash("$argon2id$v=19$m=65536,t=4,p=1$abc")
    assert hasher.needs_rehash("texto-plano")


def test_hash_invalido_no_lanza() -> None:
    hasher = PasswordHasher()

    assert not hasher.verify("x", "garbage")
    assert not hasher.verify("x", "")
    assert not hasher.verify("x", "$2y$10$ñññ")


def test_trunca_a_72_bytes_como_php() -> None:
    hasher = PasswordHasher()
    hashed = hasher.hash("a" * 72)

    # PHP: password_verify(str_repeat('a', 72) . 'b', $hash) === true
    assert hasher.verify("a" * 72 + "b", hashed)
    assert hasher.verify("a" * 200, hasher.hash("a" * 100))


def test_nul_como_php() -> None:
    hasher = PasswordHasher()

    # password_verify corta en el primer NUL; password_hash lanza ValueError.
    assert hasher.verify("abc\0xyz", hasher.hash("abc"))
    with pytest.raises(ValueError, match="null character"):
        hasher.hash("abc\0def")


def test_dummy_verify_tiene_coste_bcrypt_real() -> None:
    hasher = PasswordHasher()
    real = hasher.hash("x")

    inicio = time.perf_counter()
    hasher.verify("y", real)
    tiempo_real = time.perf_counter() - inicio

    inicio = time.perf_counter()
    hasher.dummy_verify("y")
    tiempo_dummy = time.perf_counter() - inicio

    assert tiempo_dummy > tiempo_real / 4


# --- JWT ---------------------------------------------------------------------------------------


def test_emite_y_decodifica_con_claims_esperados() -> None:
    clock = FixedClock()
    service = JwtService(JWT_SECRET, 3600, clock)

    issued = service.issue(USER_ID, "superadministrador")
    claims = service.decode(issued.token)

    assert claims.sub == USER_ID
    assert claims.rol == "superadministrador"
    assert claims.jti == issued.jti
    assert len(claims.jti) == 32
    assert claims.iat == int(clock.now().timestamp())
    assert claims.exp == claims.iat + 3600
    assert issued.expires_in == 3600

    header = json.loads(base64.urlsafe_b64decode(issued.token.split(".")[0] + "=="))
    assert header == {"alg": "HS256", "typ": "JWT"}
    payload = pyjwt.decode(issued.token, options={"verify_signature": False})
    assert set(payload) == {"sub", "rol", "jti", "iat", "exp"}


def test_cada_token_tiene_jti_distinto() -> None:
    service = JwtService(JWT_SECRET, 3600, FixedClock())

    assert service.issue(USER_ID, "estudiante").jti != service.issue(USER_ID, "estudiante").jti


def test_recorta_la_duracion_a_ocho_horas() -> None:
    service = JwtService(JWT_SECRET, 100000, FixedClock())

    assert service.ttl_seconds == 28800
    assert service.issue(USER_ID, "estudiante").expires_in == 28800


def test_decodifica_token_emitido_por_php() -> None:
    claims = JwtService("s" * 40, 28800, FixedClock()).decode(JWT_PHP)

    assert claims.sub == USER_ID
    assert claims.rol == "superadministrador"
    assert claims.jti == "0123456789abcdef0123456789abcdef"
    assert claims.exp - claims.iat == 28800


def test_rechaza_token_expirado() -> None:
    clock = FixedClock()
    service = JwtService(JWT_SECRET, 60, clock)
    token = service.issue(USER_ID, "estudiante").token

    clock.advance(59)
    service.decode(token)
    clock.advance(1)
    with pytest.raises(InvalidTokenError):
        service.decode(token)


def test_rechaza_token_emitido_en_el_futuro() -> None:
    clock = FixedClock()
    now = int(clock.now().timestamp())
    token = pyjwt.encode(
        {"sub": USER_ID, "rol": "x", "jti": "j", "iat": now + 10, "exp": now + 60},
        JWT_SECRET,
        algorithm="HS256",
    )

    with pytest.raises(InvalidTokenError):
        JwtService(JWT_SECRET, 60, clock).decode(token)


def test_rechaza_firma_invalida() -> None:
    clock = FixedClock()
    emisor = JwtService("a" * 40, 60, clock)
    verificador = JwtService(JWT_SECRET, 60, clock)

    with pytest.raises(InvalidTokenError):
        verificador.decode(emisor.issue(USER_ID, "estudiante").token)


def test_rechaza_token_manipulado() -> None:
    service = JwtService(JWT_SECRET, 60, FixedClock())
    header, _, firma = service.issue(USER_ID, "estudiante").token.split(".")
    payload = b64(
        json.dumps(
            {"sub": USER_ID, "rol": "superadministrador", "jti": "x", "iat": 1, "exp": 9999999999}
        ).encode()
    )

    with pytest.raises(InvalidTokenError):
        service.decode(f"{header}.{payload}.{firma}")


def test_rechaza_algoritmo_none() -> None:
    service = JwtService(JWT_SECRET, 60, FixedClock())
    claims = {"sub": USER_ID, "rol": "x", "jti": "x", "iat": 1, "exp": 9999999999}
    token = b64(b'{"alg":"none","typ":"JWT"}') + "." + b64(json.dumps(claims).encode()) + "."

    with pytest.raises(InvalidTokenError):
        service.decode(token)


@pytest.mark.parametrize(
    "cambios",
    [
        {"sub": "no-es-objectid"},
        {"rol": ""},
        {"rol": 5},
        {"jti": ""},
        {"iat": "1790175600"},
        {"exp": 1790175660.0},
        {"exp": True},
    ],
)
def test_rechaza_claims_incompletos(cambios: dict[str, object]) -> None:
    clock = FixedClock()
    now = int(clock.now().timestamp())
    claims: dict[str, object] = {
        "sub": USER_ID,
        "rol": "x",
        "jti": "j",
        "iat": now,
        "exp": now + 60,
    }
    claims.update(cambios)
    token = pyjwt.encode(claims, JWT_SECRET, algorithm="HS256")

    with pytest.raises(InvalidTokenError):
        JwtService(JWT_SECRET, 60, clock).decode(token)


def test_rechaza_token_firmado_con_duracion_mayor_a_ocho_horas() -> None:
    clock = FixedClock()
    now = int(clock.now().timestamp())
    token = pyjwt.encode(
        {"sub": USER_ID, "rol": "x", "jti": "j", "iat": now, "exp": now + 28801},
        JWT_SECRET,
        algorithm="HS256",
    )

    with pytest.raises(InvalidTokenError):
        JwtService(JWT_SECRET, 60, clock).decode(token)


def test_rechaza_basura() -> None:
    service = JwtService(JWT_SECRET, 60, FixedClock())

    for token in ["", "abc", "a.b.c", "a.b"]:
        with pytest.raises(InvalidTokenError):
            service.decode(token)


# --- Tokens ------------------------------------------------------------------------------------


def test_genera_tokens_hex_de_64_caracteres_unicos() -> None:
    a, b = generate_token(), generate_token()

    assert len(a) == 64
    assert all(c in "0123456789abcdef" for c in a)
    assert a != b
    assert len(generate_token(4)) == 32  # mínimo 16 bytes, como PHP


def test_hash_token_es_sha256_hex_minusculas() -> None:
    # hash('sha256', 'abc') en PHP
    esperado = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"

    assert hash_token("abc") == esperado
