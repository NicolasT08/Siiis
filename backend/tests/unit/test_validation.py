from typing import Any

import pytest

from app.errors import ApiError
from app.validation import (
    LoginInput,
    ResetPasswordInput,
    php_email_valido,
    php_float_str,
    validate_forgot_password,
    validate_login,
    validate_reset_password,
)


def campos_con_error(body: Any, validador: Any = validate_login) -> dict[str, str]:
    with pytest.raises(ApiError) as exc_info:
        validador(body)
    assert exc_info.value.status == 400
    assert exc_info.value.code == "VALIDATION_ERROR"
    assert exc_info.value.message == "Los datos enviados no son válidos."
    return exc_info.value.fields


# --- Login -------------------------------------------------------------------------------------


def test_login_normaliza_correo_y_conserva_password_literal() -> None:
    result = validate_login({"correo": "  Ana@UPTC.edu.co ", "password": " con espacios "})

    assert result == LoginInput("ana@uptc.edu.co", " con espacios ")


def test_login_ignora_campos_extra() -> None:
    body = {"correo": "a@b.co", "password": "x", "recordarme": True}

    assert validate_login(body) == LoginInput("a@b.co", "x")


@pytest.mark.parametrize("body", [None, [], "texto", {}])
def test_login_rechaza_cuerpo_vacio_o_no_objeto(body: Any) -> None:
    assert campos_con_error(body) == {
        "correo": "Este campo es obligatorio.",
        "password": "Este campo es obligatorio.",
    }


def test_login_null_y_vacio_son_obligatorios() -> None:
    assert campos_con_error({"correo": "   ", "password": None}) == {
        "correo": "Este campo es obligatorio.",
        "password": "Este campo es obligatorio.",
    }


def test_login_password_con_solo_espacios_no_se_recorta() -> None:
    assert validate_login({"correo": "a@b.co", "password": "   "}).password == "   "


@pytest.mark.parametrize("valor", [{"$gt": ""}, {"$ne": None}, ["a"], [], True, False])
def test_login_rechaza_objetos_donde_se_espera_texto(valor: Any) -> None:
    assert campos_con_error({"correo": valor, "password": valor}) == {
        "correo": "Este campo debe ser texto.",
        "password": "Este campo debe ser texto.",
    }


def test_login_acepta_numeros_como_texto_como_php() -> None:
    assert validate_login({"correo": "a@b.co", "password": 12345678}).password == "12345678"
    assert validate_login({"correo": "a@b.co", "password": 1.0}).password == "1"
    assert validate_login({"correo": "a@b.co", "password": 1.5}).password == "1.5"


def test_login_correo_con_formato_invalido() -> None:
    assert campos_con_error({"correo": "no-es-correo", "password": "x"}) == {
        "correo": "El correo no tiene un formato válido."
    }


def test_login_correo_demasiado_largo() -> None:
    correo = "a@" + ".".join(["b" * 60] * 5) + ".co"  # 310 caracteres

    assert campos_con_error({"correo": correo, "password": "x"}) == {
        "correo": "El correo no tiene un formato válido."
    }


def test_login_password_demasiado_larga() -> None:
    assert validate_login({"correo": "a@b.co", "password": "x" * 256}).password == "x" * 256
    assert campos_con_error({"correo": "a@b.co", "password": "x" * 257}) == {
        "password": "La contraseña es demasiado larga."
    }


# --- Forgot / reset ----------------------------------------------------------------------------


def test_forgot_normaliza_correo() -> None:
    assert validate_forgot_password({"correo": " ANA@uptc.edu.co"}) == "ana@uptc.edu.co"


def test_forgot_rechaza_faltante() -> None:
    assert campos_con_error({}, validate_forgot_password) == {
        "correo": "Este campo es obligatorio."
    }


def reset_min_10(body: Any) -> ResetPasswordInput:
    return validate_reset_password(body, 10)


def test_reset_acepta_datos_validos_y_recorta_token() -> None:
    assert reset_min_10({"token": " abc ", "password": "diez-chars"}) == ResetPasswordInput(
        "abc", "diez-chars"
    )


def test_reset_usa_longitud_minima_configurable() -> None:
    assert campos_con_error({"token": "abc", "password": "nueve-chr"}, reset_min_10) == {
        "password": "La contraseña debe tener al menos 10 caracteres."
    }


def test_reset_limita_a_72_bytes() -> None:
    # 36 "ñ" = 72 bytes (se acepta); 37 "ñ" = 74 bytes (se rechaza).
    assert reset_min_10({"token": "abc", "password": "ñ" * 36}).password == "ñ" * 36
    assert campos_con_error({"token": "abc", "password": "ñ" * 37}, reset_min_10) == {
        "password": "La contraseña es demasiado larga (máximo 72 bytes)."
    }


def test_reset_campos_obligatorios() -> None:
    assert campos_con_error({"token": {"$ne": ""}}, reset_min_10) == {
        "token": "Este campo debe ser texto.",
        "password": "Este campo es obligatorio.",
    }


# --- Compatibilidad con funciones de PHP (resultados obtenidos con PHP 8.2) --------------------


@pytest.mark.parametrize(
    ("correo", "valido"),
    [
        ("ana@uptc.edu.co", True),
        ("a+tag@b.co", True),
        ('"ab"@c.co', True),
        ('"a b"@c.co', False),
        ("a@[127.0.0.1]", True),
        ("a@[IPv6:2001:db8::1]", True),
        ("a@xn--bcher-kva.ch", True),
        ("a" * 64 + "@b.co", True),
        ("a@b", False),
        ("a@localhost", False),
        ("a@b..co", False),
        (".a@b.co", False),
        ("a" * 65 + "@b.co", False),
        ("ñ@b.co", False),
        ("a@b.co\n", False),
        ("a@b_c.co", False),
        ("a@-b.co", False),
        ("a@b.1com", False),
    ],
)
def test_email_igual_a_filter_validate_email(correo: str, valido: bool) -> None:
    assert php_email_valido(correo) is valido


@pytest.mark.parametrize(
    ("valor", "texto"),
    [
        (1.5, "1.5"),
        (0.1, "0.1"),
        (1.0, "1"),
        (-0.0, "-0"),
        (1e14, "1.0E+14"),
        (123456789012345.0, "1.2345678901234E+14"),
        (1 / 3, "0.33333333333333"),
        (1e-5, "1.0E-5"),
        (0.0001, "0.0001"),
        (2.5e-7, "2.5E-7"),
        (12345678.5, "12345678.5"),
    ],
)
def test_float_a_texto_como_php(valor: float, texto: str) -> None:
    assert php_float_str(valor) == texto


def test_entero_fuera_de_64_bits_como_php() -> None:
    body = {"correo": "a@b.co", "password": 10000000000000000000}

    assert validate_login(body).password == "1.0E+19"
