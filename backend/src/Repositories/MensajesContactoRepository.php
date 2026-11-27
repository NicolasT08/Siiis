<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección `Mensajes_Contacto` (spec 8.2; RF-03): trazabilidad del formulario de contacto.
 */
class MensajesContactoRepository
{
    public const ESTADO_NO_LEIDO = 'no_leido';
    public const ESTADO_LEIDO = 'leido';

    /** Lista blanca de `estado`. */
    public const ESTADOS = [self::ESTADO_NO_LEIDO, self::ESTADO_LEIDO];

    /** Más recientes primero; `_id` desempata para que las páginas sean estables. */
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
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id]);
    }

    /**
     * `$mensaje` llega completo y con su `_id` ya generado.
     *
     * @param array<string, mixed> $mensaje
     */
    public function insertar(array $mensaje): void
    {
        $this->collection()->insertOne($mensaje);
    }

    /**
     * @return bool si existía el mensaje
     */
    public function cambiarEstado(ObjectId $id, string $estado): bool
    {
        return $this->collection()->updateOne(['_id' => $id], ['$set' => ['estado' => $estado]])
            ->getMatchedCount() === 1;
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::MENSAJES_CONTACTO);
    }
}
