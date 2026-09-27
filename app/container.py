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
from app.security import JwtService, PasswordHasher

DEFAULT_STORAGE_PATH = Path(__file__).resolve().parent.parent / "storage"


@dataclass
class Container:
    settings: Settings
    clock: Clock
    logger: Logger
    mongo: Mongo
    hasher: PasswordHasher
    jwt: JwtService

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
    ) -> "Container":
        clock = clock or SystemClock()
        return cls(
            settings=settings,
            clock=clock,
            logger=logger or Logger(storage_path / "logs"),
            mongo=mongo or Mongo(settings.mongo_uri, settings.mongo_db_name),
            hasher=hasher or PasswordHasher(),
            jwt=JwtService(settings.jwt_secret, settings.jwt_ttl_seconds, clock),
        )


def get_container(request: Request) -> Container:
    container: Container = request.app.state.container
    return container
