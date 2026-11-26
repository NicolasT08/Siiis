<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección Multimedia (spec 8.2; RF-07 y RF-11). Campos: tipo, url, public_id, seccion, descripcion,
 * orden y fecha_subida. La administración (DEC-B62) usa los mismos campos.
 */
class MultimediaRepository
{
    public const SECCION_SLIDER = 'slider_principal';
    public const SECCION_VIDEOS = 'video_presentacion';
    public const SECCION_GALERIA = 'galeria';

    /** Solo los campos públicos: nunca public_id. */
    private const PROJECTION_PUBLICA = ['tipo' => 1, 'url' => 1, 'descripcion' => 1, 'orden' => 1];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * Ítems de una sección ordenados por `orden` ascendente, con tope de resultados.
     *
     * @return list<array<string, mixed>>
     */
    public function findBySeccion(string $seccion, int $limite): array
    {
        $cursor = $this->collection()->find(['seccion' => $seccion], [
            'projection' => self::PROJECTION_PUBLICA,
            'sort' => ['orden' => 1, '_id' => 1],
            'limit' => $limite,
        ]);

        /** @var list<array<string, mixed>> */
        return array_values($cursor->toArray());
    }

    /**
     * Documentos completos de una sección (incluido `public_id`), para la administración.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(string $seccion): array
    {
        $cursor = $this->collection()->find(['seccion' => $seccion], ['sort' => ['orden' => 1, '_id' => 1]]);

        /** @var list<array<string, mixed>> */
        return array_values($cursor->toArray());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id]);
    }

    public function contar(string $seccion): int
    {
        return $this->collection()->countDocuments(['seccion' => $seccion]);
    }

    /** Mayor `orden` de la sección (0 si está vacía). */
    public function maxOrden(string $seccion): int
    {
        $ultimo = $this->collection()->findOne(['seccion' => $seccion], ['sort' => ['orden' => -1], 'projection' => ['orden' => 1]]);
        $orden = is_array($ultimo) ? ($ultimo['orden'] ?? 0) : 0;

        return is_int($orden) ? $orden : 0;
    }

    /**
     * @param array{tipo: string, url: string, public_id: string, seccion: string, descripcion: string, orden: int, fecha_subida: UTCDateTime} $item
     */
    public function insertar(array $item): ObjectId
    {
        $id = $this->collection()->insertOne($item)->getInsertedId();
        if (!$id instanceof ObjectId) {
            throw new \RuntimeException('MongoDB no devolvió el _id del ítem de Multimedia.');
        }

        return $id;
    }

    /**
     * Cambia `descripcion` y/o `orden`. Devuelve el documento actualizado, o null si ya no existe.
     *
     * @param array{descripcion?: string, orden?: int} $cambios
     *
     * @return array<string, mixed>|null
     */
    public function actualizar(ObjectId $id, array $cambios): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOneAndUpdate(
            ['_id' => $id],
            ['$set' => $cambios],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
    }

    /**
     * Fija `orden` = 1..n según la lista (todos de la misma sección). Devuelve cuántos encontró.
     *
     * @param list<ObjectId> $ids
     */
    public function reordenar(string $seccion, array $ids): int
    {
        $encontrados = 0;
        foreach ($ids as $i => $id) {
            $encontrados += $this->collection()->updateOne(['_id' => $id, 'seccion' => $seccion], ['$set' => ['orden' => $i + 1]])->getMatchedCount();
        }

        return $encontrados;
    }

    /**
     * Borra el ítem y lo devuelve (para borrar después su archivo de Cloudinary), o null si no existía.
     *
     * @return array<string, mixed>|null
     */
    public function eliminar(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOneAndDelete(['_id' => $id]);
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::MULTIMEDIA);
    }
}
