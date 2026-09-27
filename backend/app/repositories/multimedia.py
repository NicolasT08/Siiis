"""Acceso a la colección Multimedia (spec 8.2; RF-07 y RF-11)."""

from app.database import Collections, Document, Mongo

SECCION_SLIDER = "slider_principal"
SECCION_VIDEOS = "video_presentacion"
SECCION_GALERIA = "galeria"

# Solo los campos públicos: nunca public_id.
PROJECTION_PUBLICA = {"tipo": 1, "url": 1, "descripcion": 1, "orden": 1}


class MultimediaRepository:
    def __init__(self, mongo: Mongo) -> None:
        self._mongo = mongo

    def find_by_seccion(self, seccion: str, limite: int) -> list[Document]:
        """Ítems de una sección ordenados por `orden` ascendente, con tope de resultados."""
        cursor = (
            self._mongo.collection(Collections.MULTIMEDIA)
            .find({"seccion": seccion}, PROJECTION_PUBLICA)
            .sort([("orden", 1), ("_id", 1)])
            .limit(limite)
        )
        return list(cursor)
