import smtplib
from email.message import EmailMessage
from typing import Any, ClassVar

import pytest

from app.mail import MailError, MailService
from tests.support import make_settings

CONFIG = {
    "mail_username": "siiis@gmail.com",
    "mail_password": "app-password",
    "mail_from_address": "",
    "mail_from_name": "Semillero SIIIS",
}


class SmtpFalso:
    instancias: ClassVar[list["SmtpFalso"]] = []

    def __init__(self, host: str, port: int, timeout: float, **kwargs: Any) -> None:
        self.host, self.port, self.timeout = host, port, timeout
        self.llamadas: list[str] = []
        self.mensaje: EmailMessage | None = None
        SmtpFalso.instancias.append(self)

    def __enter__(self) -> "SmtpFalso":
        return self

    def __exit__(self, *_: object) -> None:
        self.llamadas.append("quit")

    def starttls(self, **_: Any) -> None:
        self.llamadas.append("starttls")

    def login(self, usuario: str, clave: str) -> None:
        self.llamadas.append(f"login:{usuario}")

    def send_message(self, mensaje: EmailMessage) -> None:
        self.llamadas.append("send")
        self.mensaje = mensaje


@pytest.fixture(autouse=True)
def smtp_falso(monkeypatch: pytest.MonkeyPatch) -> None:
    SmtpFalso.instancias = []
    monkeypatch.setattr(smtplib, "SMTP", SmtpFalso)
    monkeypatch.setattr(smtplib, "SMTP_SSL", SmtpFalso)


def test_sin_credenciales_lanza_mail_error() -> None:
    service = MailService(make_settings(mail_username="", mail_password=""))

    with pytest.raises(MailError, match="SMTP no configurado"):
        service.send("a@b.co", "Asunto", "<p>Hola</p>")
    assert SmtpFalso.instancias == []


def test_envia_con_starttls_en_587() -> None:
    MailService(make_settings(**CONFIG)).send(
        "ana@uptc.edu.co", "Asunto ñ", "<p>Hola &amp; adiós</p>"
    )

    smtp = SmtpFalso.instancias[0]
    assert (smtp.host, smtp.port, smtp.timeout) == ("smtp.gmail.com", 587, 10)
    assert smtp.llamadas == ["starttls", "login:siiis@gmail.com", "send", "quit"]
    assert smtp.mensaje is not None
    assert smtp.mensaje["To"] == "ana@uptc.edu.co"
    assert smtp.mensaje["Subject"] == "Asunto ñ"
    assert smtp.mensaje["From"] == "Semillero SIIIS <siiis@gmail.com>"
    texto = smtp.mensaje.get_body(("plain",))
    html = smtp.mensaje.get_body(("html",))
    assert texto is not None and html is not None
    assert texto.get_content().strip() == "Hola &amp; adiós"  # como strip_tags() de PHP
    assert "<p>Hola &amp; adiós</p>" in html.get_content()


def test_usa_ssl_en_465_y_remitente_configurado() -> None:
    config = {**CONFIG, "mail_port": 465, "mail_from_address": "no-reply@siiis.com.co"}
    MailService(make_settings(**config)).send("a@b.co", "Asunto", "<p>x</p>", "texto plano")

    smtp = SmtpFalso.instancias[0]
    assert smtp.port == 465
    assert "starttls" not in smtp.llamadas
    assert smtp.mensaje is not None
    assert smtp.mensaje["From"] == "Semillero SIIIS <no-reply@siiis.com.co>"


def test_error_smtp_se_convierte_en_mail_error(monkeypatch: pytest.MonkeyPatch) -> None:
    def falla(self: SmtpFalso, *_: Any) -> None:
        raise smtplib.SMTPAuthenticationError(535, b"credenciales incorrectas")

    monkeypatch.setattr(SmtpFalso, "login", falla)

    with pytest.raises(MailError, match="Error SMTP"):
        MailService(make_settings(**CONFIG)).send("a@b.co", "Asunto", "<p>x</p>")
