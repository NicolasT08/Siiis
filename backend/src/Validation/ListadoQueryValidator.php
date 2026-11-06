<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Support\ApiException;

/**
 * Query string de los listados paginados con filtro de estado de la fase 4 (`GET /reviews`,
 * `GET /contact/messages` y `GET /inscriptions`): `page`, `per_page` y un campo de estado de lista blanca.
 * Mismas reglas que `GET /users` (DEC-B26).
 */
final class ListadoQueryValidator
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed>      $query   normalmente $request->getQueryParams()
     * @param list<string>|null $estados valores permitidos de `$campoEstado`; null = el filtro se ignora
     *
     * @return array{page: int, per_page: int, estado: string|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public static function validar(
        array $query,
        int $perPageDefault,
        int $perPageMax,
        string $campoEstado,
        ?array $estados,
    ): array {
        $errors = [];
        $page = QueryReader::entero($query, 'page', 1, QueryReader::MAX_PAGE, 1, $errors);
        $perPage = QueryReader::entero($query, 'per_page', 1, $perPageMax, $perPageDefault, $errors);
        $estado = $estados !== null ? QueryReader::opcion($query, $campoEstado, $estados, $errors) : null;

        if ($errors !== []) {
            throw ApiException::validation($errors, 'Los parámetros de la consulta no son válidos.');
        }

        return ['page' => $page, 'per_page' => $perPage, 'estado' => $estado];
    }
}
