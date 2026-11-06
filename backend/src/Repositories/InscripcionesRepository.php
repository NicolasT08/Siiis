<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección `Inscripciones` (spec 8.2; RF-12, RF-13). Independiente de `Usuarios` hasta que un
 * administrador crea la cuenta a mano (DEC-B52).
 */
class InscripcionesRepository
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ACEPTADA = 'aceptada';
    public const ESTADO_RECHAZADA = 'rechazada';

    /** Lista blanca de `estado_solicitud`. */
    public const ESTADOS = [self::ESTADO_PENDIENTE, self::ESTADO_ACEPTADA, self::ESTADO_RECHAZADA];

    /** Resumen para el listado del panel (spec 10.13.4): sin la carta ni los documentos. */
    private const PROJECTION_RESUMEN = [
        'nombre_completo' => 1,
        'correo' => 1,
        'telefono' => 1,
        'programa_academico' => 1,
        'semestre' => 1,
        'estado_solicitud' => 1,
        'fecha_solicitud' => 1,
    ];

    /** Más recientes primero; `_id` desempata para que las páginas sean estables. */
    private const ORDEN = ['fecha_solicitud' => -1, '_id' => -1];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * `$estado` llega de la lista blanca; null = todas. Usa el índice oficial `estado_solicitud` (spec 8.4).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginar(?string $estado, int $page, int $perPage): array
    {
        $filtro = $estado !== null ? ['estado_solicitud' => $estado] : [];
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
     * Solicitud completa (con la carta y los documentos).
     *
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id]);
    }

    /**
     * Si el correo (ya en minúsculas) tiene una solicitud pendiente (DEC-B51).
     */
    public function tienePendiente(string $correo): bool
    {
        return $this->collection()->countDocuments(
            ['correo' => $correo, 'estado_solicitud' => self::ESTADO_PENDIENTE],
            ['limit' => 1],
        ) > 0;
    }

    /**
     * `$inscripcion` llega completa y con su `_id` ya generado.
     *
     * @param array<string, mixed> $inscripcion
     */
    public function insertar(array $inscripcion): void
    {
        $this->collection()->insertOne($inscripcion);
    }

    /**
     * Pasa la solicitud de `pendiente` a `$estado` en una sola operación atómica (DEC-B52): si otro
     * administrador ya la resolvió, no cambia nada.
     *
     * @return bool si se resolvió
     */
    public function resolver(ObjectId $id, string $estado): bool
    {
        return $this->collection()->updateOne(
            ['_id' => $id, 'estado_solicitud' => self::ESTADO_PENDIENTE],
            ['$set' => ['estado_solicitud' => $estado]],
        )->getModifiedCount() === 1;
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::INSCRIPCIONES);
    }
}
