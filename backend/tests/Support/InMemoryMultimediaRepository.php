<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use Siiis\Repositories\MultimediaRepository;

final class InMemoryMultimediaRepository extends MultimediaRepository
{
    /** @var list<array{string, int}> */
    public array $llamadas = [];

    /** @var array<string, array<string, mixed>> por _id */
    public array $items = [];

    public bool $fallarAlInsertar = false;

    /**
     * @param list<array<string, mixed>> $docs
     */
    public function __construct(array $docs = [])
    {
        foreach ($docs as $doc) {
            $doc['_id'] ??= new ObjectId();
            $this->items[(string) $doc['_id']] = $doc;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deSeccion(string $seccion): array
    {
        $items = array_values(array_filter($this->items, static fn (array $d): bool => ($d['seccion'] ?? null) === $seccion));
        usort($items, static fn (array $a, array $b): int => [$a['orden'], (string) $a['_id']] <=> [$b['orden'], (string) $b['_id']]);

        return $items;
    }

    public function findBySeccion(string $seccion, int $limite): array
    {
        $this->llamadas[] = [$seccion, $limite];

        return array_map(
            static fn (array $d): array => array_intersect_key($d, array_flip(['_id', 'tipo', 'url', 'descripcion', 'orden'])),
            array_slice($this->deSeccion($seccion), 0, $limite),
        );
    }

    public function listar(string $seccion): array
    {
        return $this->deSeccion($seccion);
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->items[(string) $id] ?? null;
    }

    public function contar(string $seccion): int
    {
        return count($this->deSeccion($seccion));
    }

    public function maxOrden(string $seccion): int
    {
        $ordenes = array_column($this->deSeccion($seccion), 'orden');

        return $ordenes === [] ? 0 : (int) max($ordenes);
    }

    public function insertar(array $item): ObjectId
    {
        if ($this->fallarAlInsertar) {
            throw new \RuntimeException('Fallo simulado de MongoDB');
        }
        $id = new ObjectId();
        $this->items[(string) $id] = ['_id' => $id] + $item;

        return $id;
    }

    public function actualizar(ObjectId $id, array $cambios): ?array
    {
        if (!isset($this->items[(string) $id])) {
            return null;
        }
        $this->items[(string) $id] = $cambios + $this->items[(string) $id];

        return $this->items[(string) $id];
    }

    public function reordenar(string $seccion, array $ids): int
    {
        $encontrados = 0;
        foreach ($ids as $i => $id) {
            if (($this->items[(string) $id]['seccion'] ?? null) === $seccion) {
                $this->items[(string) $id]['orden'] = $i + 1;
                $encontrados++;
            }
        }

        return $encontrados;
    }

    public function eliminar(ObjectId $id): ?array
    {
        $doc = $this->items[(string) $id] ?? null;
        unset($this->items[(string) $id]);

        return $doc;
    }
}
