"""Recuperación de contraseña con token de un solo uso (RF-04, spec 12.3).

Réplica de PasswordResetService.php. En Credenciales.token_recuperacion solo se guarda el hash
SHA-256 del token.
"""

import hmac
import re
from datetime import UTC, datetime, timedelta
from urllib.parse import quote

from bson import ObjectId

from app.clock import Clock
from app.config import Settings
from app.errors import ApiError
from app.logger import Logger
from app.mail import MailService
from app.repositories.credenciales import CredencialesRepository
from app.repositories.sesiones import SesionesRepository
from app.repositories.usuarios import UsuariosRepository
from app.security import PasswordHasher, generate_token, hash_token

ASUNTO = "Restablece tu contraseña — Semillero SIIIS"
_TOKEN_RE = re.compile(r"[a-f0-9]{64}", re.ASCII)


def _token_invalido() -> ApiError:
    return ApiError(
        400, "INVALID_OR_EXPIRED_TOKEN", "El enlace de recuperación no es válido o ya venció."
    )


def _escape_html(valor: str) -> str:
    """htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') de PHP."""
    return (
        valor.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&apos;")
    )


class PasswordResetService:
    def __init__(
        self,
        credenciales: CredencialesRepository,
        usuarios: UsuariosRepository,
        sesiones: SesionesRepository,
        hasher: PasswordHasher,
        mail: MailService,
        clock: Clock,
        settings: Settings,
        logger: Logger,
    ) -> None:
        self._credenciales = credenciales
        self._usuarios = usuarios
        self._sesiones = sesiones
        self._hasher = hasher
        self._mail = mail
        self._clock = clock
        self._settings = settings
        self._logger = logger

    def solicitar(self, correo: str) -> None:
        """Genera y envía el enlace si la cuenta puede recuperarse. No revela nada al llamador:
        cualquier caso termina sin excepción (la ruta responde siempre el mismo mensaje)."""
        credencial = self._credenciales.find_by_correo(correo)
        credencial_id = credencial.get("_id") if credencial else None
        if credencial is None or not isinstance(credencial_id, ObjectId):
            return

        # DEC-B09: solo cuentas activas con usuario activo.
        usuario = self._usuarios.find_by_credencial_id(credencial_id)
        estado_usuario = usuario.get("estado") if usuario else None
        if credencial.get("estado_cuenta") != "activa" or estado_usuario is not True:
            return

        token = generate_token()
        expiracion = self._clock.now() + timedelta(
            minutes=self._settings.password_reset_ttl_minutes
        )
        self._credenciales.guardar_token_recuperacion(credencial_id, hash_token(token), expiracion)

        nombre = usuario.get("nombre") if usuario else None
        enlace = self._enlace(token)
        try:
            self._mail.send(
                correo,
                ASUNTO,
                self._html_correo(enlace, nombre if isinstance(nombre, str) else ""),
                self._texto_correo(enlace),
            )
        except Exception as exc:
            # El cliente recibe la respuesta genérica igualmente.
            self._logger.error(
                "No se pudo enviar el correo de recuperación",
                {"credencial_id": str(credencial_id), "error": str(exc)},
            )

    def restablecer(self, token: str, password: str) -> None:
        if _TOKEN_RE.fullmatch(token) is None:
            raise _token_invalido()

        token_hash = hash_token(token)
        credencial = self._credenciales.find_by_token_recuperacion(token_hash)
        guardado = credencial.get("token_recuperacion") if credencial else None
        expiracion = credencial.get("token_expiracion") if credencial else None
        credencial_id = credencial.get("_id") if credencial else None
        usuario_id = credencial.get("usuario_id") if credencial else None

        if (
            not isinstance(guardado, str)
            or not hmac.compare_digest(guardado, token_hash)
            or not isinstance(expiracion, datetime)
            or _utc(expiracion) <= self._clock.now()
            or not isinstance(credencial_id, ObjectId)
        ):
            raise _token_invalido()

        self._credenciales.cambiar_password(credencial_id, self._hasher.hash(password))
        if isinstance(usuario_id, ObjectId):
            self._sesiones.desactivar_todas(usuario_id)

    def _enlace(self, token: str) -> str:
        base = self._settings.password_reset_url
        return f"{base}{'&' if '?' in base else '?'}token={quote(token, safe='')}"

    def _html_correo(self, enlace: str, nombre: str) -> str:
        saludo = f"Hola, {_escape_html(nombre)}:" if nombre != "" else "Hola:"
        minutos = self._settings.password_reset_ttl_minutes
        return (
            f"<p>{saludo}</p>\n"
            "<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta del "
            "Semillero SIIIS.</p>\n"
            f'<p><a href="{_escape_html(enlace)}">Restablecer contraseña</a></p>\n'
            f"<p>El enlace vence en {minutos} minutos y solo puede usarse una vez.</p>\n"
            "<p>Si no solicitaste este cambio, ignora este correo; tu contraseña no cambiará.</p>"
        )

    def _texto_correo(self, enlace: str) -> str:
        minutos = self._settings.password_reset_ttl_minutes
        return (
            f"Para restablecer tu contraseña del Semillero SIIIS abre este enlace:\n{enlace}\n\n"
            f"Vence en {minutos} minutos. Si no lo solicitaste, ignora este correo."
        )


def _utc(valor: datetime) -> datetime:
    return valor if valor.tzinfo is not None else valor.replace(tzinfo=UTC)
