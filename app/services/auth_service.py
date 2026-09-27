"""Reglas de autenticación local (RF-02, RNF-05; spec 12 y 10.13.6). Réplica de AuthService.php."""

from typing import Any

from bson import ObjectId

from app.clock import Clock
from app.config import Settings
from app.database import Document
from app.errors import ApiError
from app.logger import Logger
from app.repositories.credenciales import CredencialesRepository
from app.repositories.sesiones import SesionesRepository
from app.repositories.usuarios import UsuariosRepository
from app.security import JwtService, PasswordHasher, hash_token
from app.serializers import serialize, to_object_id


def _credenciales_invalidas() -> ApiError:
    return ApiError(401, "INVALID_CREDENTIALS", "Correo o contraseña incorrectos.")


def usuario_publico(usuario: Document) -> dict[str, Any]:
    """Forma pública del usuario: {id, nombre, apellido, rol, foto_perfil}."""
    foto = usuario.get("foto_perfil")
    url = foto.get("url") if isinstance(foto, dict) else None
    data: dict[str, Any] = serialize(
        {
            "_id": usuario.get("_id"),
            "nombre": usuario.get("nombre"),
            "apellido": usuario.get("apellido"),
            "rol": usuario.get("rol"),
            "foto_perfil": {"url": url} if isinstance(url, str) else None,
        }
    )
    return data


class AuthService:
    def __init__(
        self,
        credenciales: CredencialesRepository,
        usuarios: UsuariosRepository,
        sesiones: SesionesRepository,
        hasher: PasswordHasher,
        jwt: JwtService,
        clock: Clock,
        settings: Settings,
        logger: Logger,
    ) -> None:
        self._credenciales = credenciales
        self._usuarios = usuarios
        self._sesiones = sesiones
        self._hasher = hasher
        self._jwt = jwt
        self._clock = clock
        self._settings = settings
        self._logger = logger

    def login(self, correo: str, password: str, ip: str) -> dict[str, Any]:
        """Login local. Orden anti-enumeración (DEC-B07): contraseña → estado → usuario."""
        credencial = self._credenciales.find_by_correo(correo)
        hashed = credencial.get("password") if credencial else None
        credencial_id = credencial.get("_id") if credencial else None

        if (
            credencial is None
            or not isinstance(hashed, str)
            or not isinstance(credencial_id, ObjectId)
        ):
            self._hasher.dummy_verify(password)
            raise _credenciales_invalidas()

        if not self._hasher.verify(password, hashed):
            intentos = self._credenciales.registrar_intento_fallido(credencial_id)
            if intentos >= self._settings.max_login_attempts:
                # DEC-B06: umbral alcanzado; el bloqueo está pendiente, solo se registra.
                self._logger.warning(
                    "Umbral de intentos fallidos alcanzado",
                    {"credencial_id": str(credencial_id), "intentos": intentos},
                )
            raise _credenciales_invalidas()

        estado_cuenta = credencial.get("estado_cuenta")
        if estado_cuenta == "pendiente_verificacion":
            raise ApiError(403, "ACCOUNT_PENDING", "La cuenta está pendiente de verificación.")
        if estado_cuenta != "activa":
            raise ApiError(403, "ACCOUNT_BLOCKED", "La cuenta está bloqueada.")

        usuario = self._usuarios.find_by_credencial_id(credencial_id)
        usuario_id = usuario.get("_id") if usuario else None
        rol = usuario.get("rol") if usuario else None
        if usuario is None or not isinstance(usuario_id, ObjectId) or not isinstance(rol, str):
            self._logger.error(
                "Credencial sin perfil de Usuarios válido", {"credencial_id": str(credencial_id)}
            )
            raise _credenciales_invalidas()
        if usuario.get("estado") is not True:
            raise ApiError(403, "ACCOUNT_INACTIVE", "El usuario está inactivo.")

        issued = self._jwt.issue(str(usuario_id), rol)
        self._sesiones.crear(
            usuario_id, hash_token(issued.jti), issued.issued_at, issued.expires_at, ip
        )
        self._credenciales.registrar_acceso_exitoso(
            credencial_id,
            self._clock.now(),
            self._hasher.hash(password) if self._hasher.needs_rehash(hashed) else None,
        )

        return {
            "token": issued.token,
            "expira_en": issued.expires_in,
            "usuario": usuario_publico(usuario),
        }

    def usuario_actual(self, usuario_id: str) -> dict[str, Any]:
        """Perfil del usuario autenticado; 401 si ya no existe o fue desactivado."""
        oid = to_object_id(usuario_id)
        usuario = self._usuarios.find_by_id(oid) if oid is not None else None
        if usuario is None or usuario.get("estado") is not True:
            raise ApiError.unauthorized()
        return usuario_publico(usuario)

    def logout(self, session_token_hash: str) -> None:
        self._sesiones.desactivar(session_token_hash)
