"""Dependencias de la aplicación (equivalente al contenedor PHP-DI, DEC-B01).

Las pruebas construyen el contenedor con dobles en memoria para repositorios y servicios.
"""

from dataclasses import dataclass
from pathlib import Path

from fastapi import Request

from app.clock import Clock, SystemClock
from app.config import Settings
from app.database import Mongo
from app.logger import Logger
from app.mail import MailService
from app.rate_limit import RateLimiter
from app.repositories.credenciales import CredencialesRepository
from app.repositories.multimedia import MultimediaRepository
from app.repositories.sesiones import SesionesRepository
from app.repositories.usuarios import UsuariosRepository
from app.security import JwtService, PasswordHasher
from app.services.auth_service import AuthService
from app.services.password_reset_service import PasswordResetService

DEFAULT_STORAGE_PATH = Path(__file__).resolve().parent.parent / "storage"


@dataclass
class Container:
    settings: Settings
    clock: Clock
    logger: Logger
    mongo: Mongo
    hasher: PasswordHasher
    jwt: JwtService
    rate_limiter: RateLimiter
    credenciales: CredencialesRepository
    usuarios: UsuariosRepository
    sesiones: SesionesRepository
    multimedia: MultimediaRepository
    mail: MailService
    auth: AuthService
    password_reset: PasswordResetService

    @classmethod
    def build(
        cls,
        settings: Settings,
        *,
        storage_path: Path = DEFAULT_STORAGE_PATH,
        clock: Clock | None = None,
        logger: Logger | None = None,
        mongo: Mongo | None = None,
        hasher: PasswordHasher | None = None,
        credenciales: CredencialesRepository | None = None,
        usuarios: UsuariosRepository | None = None,
        sesiones: SesionesRepository | None = None,
        multimedia: MultimediaRepository | None = None,
        mail: MailService | None = None,
    ) -> "Container":
        clock = clock or SystemClock()
        logger = logger or Logger(storage_path / "logs")
        mongo = mongo or Mongo(settings.mongo_uri, settings.mongo_db_name)
        hasher = hasher or PasswordHasher()
        jwt = JwtService(settings.jwt_secret, settings.jwt_ttl_seconds, clock)
        credenciales = credenciales or CredencialesRepository(mongo)
        usuarios = usuarios or UsuariosRepository(mongo)
        sesiones = sesiones or SesionesRepository(mongo)
        mail = mail or MailService(settings)

        return cls(
            settings=settings,
            clock=clock,
            logger=logger,
            mongo=mongo,
            hasher=hasher,
            jwt=jwt,
            rate_limiter=RateLimiter(clock),
            credenciales=credenciales,
            usuarios=usuarios,
            sesiones=sesiones,
            multimedia=multimedia or MultimediaRepository(mongo),
            mail=mail,
            auth=AuthService(
                credenciales, usuarios, sesiones, hasher, jwt, clock, settings, logger
            ),
            password_reset=PasswordResetService(
                credenciales, usuarios, sesiones, hasher, mail, clock, settings, logger
            ),
        )


def get_container(request: Request) -> Container:
    container: Container = request.app.state.container
    return container
