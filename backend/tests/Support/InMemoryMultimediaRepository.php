<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Siiis\Repositories\MultimediaRepository;

/**
 * Doble de MultimediaRepository que imita find con filtro, proyección, orden y límite.
 */
final class InMemoryMultimediaRepository extends MultimediaRepository
{
    /** @var list<array{string, int}> */
    public array $llamadas = [];

    /**
     * @param list<array<string, mixed>> $docs
     */
    public function __construct(private readonly array $docs)
    {
    }

    public function findBySeccion(string $seccion, int $limite): array
    {
        $this->llamadas[] = [$seccion, $limite];
        $items = array_values(array_filter($this->docs, static fn (array $d): bool => $d['seccion'] === $seccion));
        usort($items, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);

        return array_map(
            static fn (array $d): array => array_intersect_key($d, array_flip(['_id', 'tipo', 'url', 'descripcion', 'orden'])),
            array_slice($items, 0, $limite),
        );
    }
}
