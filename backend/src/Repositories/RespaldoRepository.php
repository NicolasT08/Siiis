<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\Document;
use Siiis\Database\Mongo;

/**
 * Lectura completa de la base para el respaldo (auditoría A-01, `scripts/respaldar_base.php`). Solo lectura:
 * lista las colecciones y recorre sus documentos. Nunca escribe en MongoDB.
 */
class RespaldoRepository
{
    /** Los documentos llegan como BSON crudo: se conservan los tipos exactos (Int32/Int64, decimales, fechas). */
    private const TYPE_MAP_BSON = ['root' => 'bson', 'document' => 'bson', 'array' => 'bson'];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    public function databaseName(): string
    {
        return $this->mongo->databaseName();
    }

    /**
     * Colecciones de la base, en orden alfabético, sin las internas (`system.*`) ni las vistas.
     *
     * @return list<string>
     */
    public function colecciones(): array
    {
        $nombres = [];
        foreach ($this->mongo->database()->listCollections(['filter' => ['type' => 'collection']]) as $info) {
            $nombre = $info->getName();
            if (!str_starts_with($nombre, 'system.')) {
                $nombres[] = $nombre;
            }
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /**
     * Todos los documentos de la colección, ordenados por `_id`.
     *
     * @return iterable<Document>
     */
    public function documentos(string $coleccion): iterable
    {
        $cursor = $this->mongo->collection($coleccion)->find([], [
            'sort' => ['_id' => 1],
            'typeMap' => self::TYPE_MAP_BSON,
        ]);
        foreach ($cursor as $documento) {
            if ($documento instanceof Document) {
                yield $documento;
            }
        }
    }
}
