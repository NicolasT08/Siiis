<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección `Reseñas` (spec 8.2): opiniones sobre el semillero (DEC-B43).
 */
class ResenasRepository
{
    public const ESTADO_VISIBLE = 'visible';
    public const ESTADO_OCULTA = 'oculta';

    /** Lista blanca de `estado`. */
    public const ESTADOS = [self::ESTADO_VISIBLE, self::ESTADO_OCULTA];

    /** Más recientes primero; `_id` desempata para que las páginas sean estables (DEC-B45). */
    private const ORDEN = ['fecha' => -1, '_id' => -1];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * `$estado` llega de la lista blanca; null = todos.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginar(?string $estado, int $page, int $perPage): array
    {
        $filtro = $estado !== null ? ['estado' => $estado] : [];
        $cursor = $this->collection()->find($filtro, [
            'sort' => self::ORDEN,
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = array_values($cursor->toArray());

        return ['items' => $items, 'total' => $this->collection()->countDocuments($filtro)];
    }

    /**
     * Promedio y cantidad de calificaciones de las reseñas visibles (DEC-B45). `promedio` es null si no hay
     * ninguna.
     *
     * @return array{promedio: float|null, calificaciones: int}
     */
    public function resumenVisibles(): array
    {
        $cursor = $this->collection()->aggregate([
            ['$match' => ['estado' => self::ESTADO_VISIBLE, 'calificacion' => ['$type' => 'number']]],
            ['$group' => ['_id' => null, 'promedio' => ['$avg' => '$calificacion'], 'total' => ['$sum' => 1]]],
        ]);

        foreach ($cursor as $fila) {
            /** @var array<string, mixed> $fila */
            $promedio = $fila['promedio'] ?? null;
            $total = $fila['total'] ?? 0;

            return [
                'promedio' => is_int($promedio) || is_float($promedio) ? (float) $promedio : null,
                'calificaciones' => is_int($total) ? $total : 0,
            ];
        }

        return ['promedio' => null, 'calificaciones' => 0];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id]);
    }

    /**
     * `$resena` llega completa y con su `_id` ya generado.
     *
     * @param array<string, mixed> $resena
     */
    public function insertar(array $resena): void
    {
        $this->collection()->insertOne($resena);
    }

    /**
     * @return bool si existía la reseña
     */
    public function cambiarEstado(ObjectId $id, string $estado): bool
    {
        return $this->collection()->updateOne(['_id' => $id], ['$set' => ['estado' => $estado]])
            ->getMatchedCount() === 1;
    }

    /**
     * Borrado físico (DEC-B44).
     *
     * @return bool si se borró
     */
    public function eliminar(ObjectId $id): bool
    {
        return $this->collection()->deleteOne(['_id' => $id])->getDeletedCount() === 1;
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::RESENAS);
    }
}
