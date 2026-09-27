import pytest

from app.errors import ApiError
from app.request_body import parse_form_body, parse_json_body

INVALIDO = "El cuerpo de la petición no es un JSON válido."
NO_OBJETO = "El cuerpo de la petición debe ser un objeto JSON."


def error_de(raw: bytes) -> str:
    with pytest.raises(ApiError) as exc_info:
        parse_json_body(raw)
    assert exc_info.value.status == 400
    assert exc_info.value.code == "INVALID_JSON"
    return exc_info.value.message


def test_objeto_valido() -> None:
    assert parse_json_body(b' {"correo": "a@b.co", "n": [1, {"x": null}]} \n') == {
        "correo": "a@b.co",
        "n": [1, {"x": None}],
    }


def test_cuerpo_vacio_es_objeto_vacio() -> None:
    assert parse_json_body(b"") == {}
    assert parse_json_body(b" \n\t") == {}


@pytest.mark.parametrize(
    "raw",
    [
        b"{",
        b"{'a': 1}",
        b'{"a": NaN}',
        b'{"a": Infinity}',
        b'{"a": 1,}',
        b"\xef\xbb\xbf{}",  # BOM
        b'{"a": "\xff"}',  # UTF-8 inválido
        b'{"a": "\\ud800"}',  # surrogate suelto
        b'{"\\udc00": 1}',
        b"[" * 5000 + b"]" * 5000,
    ],
)
def test_json_invalido(raw: bytes) -> None:
    assert error_de(raw) == INVALIDO


@pytest.mark.parametrize("raw", [b"[]", b'["a"]', b'"texto"', b"123", b"null", b"true"])
def test_json_que_no_es_objeto(raw: bytes) -> None:
    assert error_de(raw) == NO_OBJETO


def test_profundidad_maxima_de_php() -> None:
    # json_decode($raw, true, 32) admite 31 niveles de anidamiento, no 32.
    def anidado(niveles: int) -> bytes:
        return b'{"a":' * (niveles - 1) + b"{}" + b"}" * (niveles - 1)

    assert parse_json_body(anidado(31))
    assert error_de(anidado(32)) == INVALIDO


def test_surrogates_emparejados_son_validos() -> None:
    assert parse_json_body(b'{"a": "\\ud83d\\ude00"}') == {"a": "\U0001f600"}


def test_formulario_urlencoded() -> None:
    assert parse_form_body(b"correo=a%40b.co&password=x+y&vacio=") == {
        "correo": "a@b.co",
        "password": "x y",
        "vacio": "",
    }
    # PHP convierte campo[...] en arreglo: el validador lo reporta como "no es texto".
    assert parse_form_body(b"correo[$ne]=&password[]=1") == {"correo": {}, "password": {}}
