<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Bloque `meta` de los listados paginados (mismo formato que `GET /users`, DEC-B26).
 */
final class Paginacion
{
    private function __construct()
    {
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public static function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }
}
