<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Support\ModuloDocumentos;

/**
 * Acceso a la colección unificada `Documentos` (spec 8.2): proyectos, artículos y noticias.
 */
class DocumentosRepository
{
    /** Resumen para listados (spec 10.13.4): nunca el `contenido` completo. */
    private const PROJECTION_RESUMEN = [
        'categoria' => 1,
        'titulo' => 1,
        'descripcion' => 1,
        'autor_id' => 1,
        'integrantes' => 1,
        'estado' => 1,
        'visible' => 1,
        'fecha_creacion' => 1,
        'fecha_actualizacion' => 1,
    ];

    /** Más recientes primero; `_id` desempata para que las páginas sean estables. */
    private const ORDEN = ['fecha_creacion' => -1, '_id' => -1];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginar(DocumentoCriterios $criterios, int $page, int $perPage): array
    {
        $filtro = self::filtro($criterios);

        $cursor = $this->collection()->find($filtro, [
            'projection' => self::PROJECTION_RESUMEN,
            'sort' => self::ORDEN,
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = array_values($cursor->toArray());

        return ['items' => $items, 'total' => $this->collection()->countDocuments($filtro)];
    }

    /**
     * Documento completo (con `contenido`).
     *
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id]);
    }

    /**
     * `$documento` llega completo y con su `_id` ya generado.
     *
     * @param array<string, mixed> $documento
     */
    public function insertar(array $documento): void
    {
        $this->collection()->insertOne($documento);
    }

    /**
     * `$set` llega validado (claves de listas blancas). Si se pasa `$siEstado`, solo actualiza cuando el
     * documento sigue en ese estado: así la edición de un autor no pisa una publicación simultánea.
     *
     * @param array<string, mixed> $set
     *
     * @return bool si había un documento que cumpliera la condición
     */
    public function actualizar(ObjectId $id, array $set, ?string $siEstado = null): bool
    {
        $filtro = ['_id' => $id];
        if ($siEstado !== null) {
            $filtro['estado'] = $siEstado;
        }

        return $this->collection()->updateOne($filtro, ['$set' => $set])->getMatchedCount() === 1;
    }

    /**
     * Agrega un adjunto en una sola operación atómica: solo si aún hay cupo (`$max`) y, con `$siEstado`,
     * si el documento sigue en ese estado. Así dos subidas simultáneas no superan el límite.
     *
     * @param array{url: string, public_id: string, nombre_archivo: string} $adjunto
     *
     * @return bool si se agregó
     */
    public function agregarAdjunto(
        ObjectId $id,
        array $adjunto,
        int $max,
        UTCDateTime $fecha,
        ?string $siEstado = null,
    ): bool {
        $filtro = ['_id' => $id, 'archivos_adjuntos.' . ($max - 1) => ['$exists' => false]];
        if ($siEstado !== null) {
            $filtro['estado'] = $siEstado;
        }

        return $this->collection()->updateOne($filtro, [
            '$push' => ['archivos_adjuntos' => $adjunto],
            '$set' => ['fecha_actualizacion' => $fecha],
        ])->getMatchedCount() === 1;
    }

    /**
     * Quita el adjunto con ese `public_id` (con `$siEstado`, igual que en `actualizar`).
     *
     * @return bool si se quitó
     */
    public function quitarAdjunto(ObjectId $id, string $publicId, UTCDateTime $fecha, ?string $siEstado = null): bool
    {
        $filtro = ['_id' => $id, 'archivos_adjuntos.public_id' => $publicId];
        if ($siEstado !== null) {
            $filtro['estado'] = $siEstado;
        }

        return $this->collection()->updateOne($filtro, [
            '$pull' => ['archivos_adjuntos' => ['public_id' => $publicId]],
            '$set' => ['fecha_actualizacion' => $fecha],
        ])->getModifiedCount() === 1;
    }

    /**
     * Borrado físico (DEC-B35). Con `$siEstado`, igual que en `actualizar`.
     *
     * @return bool si se borró
     */
    public function eliminar(ObjectId $id, ?string $siEstado = null): bool
    {
        $filtro = ['_id' => $id];
        if ($siEstado !== null) {
            $filtro['estado'] = $siEstado;
        }

        return $this->collection()->deleteOne($filtro)->getDeletedCount() === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function filtro(DocumentoCriterios $c): array
    {
        $condiciones = [
            ['categoria' => count($c->categorias) === 1 ? $c->categorias[0] : ['$in' => $c->categorias]],
        ];
        if ($c->estado !== null) {
            $condiciones[] = ['estado' => $c->estado];
        }
        if ($c->visible !== null) {
            $condiciones[] = ['visible' => $c->visible];
        }
        if ($c->autor !== null) {
            $condiciones[] = ['autor_id' => $c->autor];
        }
        if ($c->integrante !== null) {
            $condiciones[] = ['integrantes' => $c->integrante];
        }

        $publico = ['visible' => true, 'estado' => ['$ne' => ModuloDocumentos::ESTADO_EN_REVISION]];
        if ($c->alcance === DocumentoCriterios::ALCANCE_PROPIO && $c->actor !== null) {
            $condiciones[] = ['$or' => [
                $publico,
                ['autor_id' => $c->actor],
                ['integrantes' => $c->actor],
            ]];
        } elseif ($c->alcance !== DocumentoCriterios::ALCANCE_TODO) {
            // Cualquier alcance desconocido o "propio" sin actor se reduce a lo público.
            $condiciones[] = $publico;
        }

        return count($condiciones) === 1 ? $condiciones[0] : ['$and' => $condiciones];
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::DOCUMENTOS);
    }
}
