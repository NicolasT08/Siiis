"""Envío centralizado de correo por SMTP (spec 10.6 y 14), con smtplib (DEC-P04).

Misma configuración que MailService.php (PHPMailer): puerto 465 → SSL; otro puerto → STARTTLS;
10 s de timeout; remitente MAIL_FROM_ADDRESS o, si está vacío, MAIL_USERNAME.
"""

import re
import smtplib
import ssl
from email.headerregistry import Address
from email.message import EmailMessage

from app.config import Settings

TIMEOUT_SECONDS = 10


class MailError(Exception):
    """Fallo del proveedor de correo (SMTP). El mensaje no incluye credenciales."""


class MailService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def send(self, to: str, subject: str, html: str, text: str = "") -> None:
        """Lanza MailError si falta configuración o el SMTP falla."""
        s = self._settings
        if s.mail_username == "" or s.mail_password == "":
            raise MailError("SMTP no configurado (MAIL_USERNAME / MAIL_PASSWORD).")

        mensaje = EmailMessage()
        remitente = s.mail_from_address or s.mail_username
        mensaje["From"] = str(Address(display_name=s.mail_from_name, addr_spec=remitente))
        mensaje["To"] = to
        mensaje["Subject"] = subject
        mensaje.set_content(text or _strip_tags(html).strip())
        mensaje.add_alternative(html, subtype="html")

        contexto = ssl.create_default_context()
        try:
            if s.mail_port == 465:
                with smtplib.SMTP_SSL(
                    s.mail_host, s.mail_port, timeout=TIMEOUT_SECONDS, context=contexto
                ) as smtp:
                    smtp.login(s.mail_username, s.mail_password)
                    smtp.send_message(mensaje)
            else:
                with smtplib.SMTP(s.mail_host, s.mail_port, timeout=TIMEOUT_SECONDS) as smtp:
                    smtp.starttls(context=contexto)
                    smtp.login(s.mail_username, s.mail_password)
                    smtp.send_message(mensaje)
        except (smtplib.SMTPException, OSError) as exc:
            raise MailError(f"Error SMTP: {type(exc).__name__}: {exc}") from exc


def _strip_tags(html: str) -> str:
    """strip_tags() de PHP: quita las etiquetas y deja las entidades HTML tal cual."""
    return re.sub(r"<[^>]*>", "", html)
