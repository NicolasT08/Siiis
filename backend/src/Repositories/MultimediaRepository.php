<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección Multimedia (spec 8.2; RF-07 y RF-11).
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

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::MULTIMEDIA);
    }
}
