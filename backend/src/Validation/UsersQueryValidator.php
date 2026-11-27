<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;

/**
 * Query string de GET /users (spec 10.13.4; DEC-B26): `page`, `per_page`, `rol` y, solo para
 * administradores, `estado`. Un parámetro repetido como arreglo (`rol[$ne]=x`) es un error del campo.
 */
final class UsersQueryValidator
{
    /** Tope de página: evita desbordar `skip` con números enormes. */
    public const MAX_PAGE = QueryReader::MAX_PAGE;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array<mixed> $query       normalmente $request->getQueryParams()
     * @param bool         $verEstado si el actor puede filtrar por `estado` (administradores);
     *                                si no, el parámetro se ignora y el servicio fija `estado = true`
     *
     * @return array{page: int, per_page: int, rol: string|null, estado: bool|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(array $query, bool $verEstado): array
    {
        $errors = [];

        $page = QueryReader::entero($query, 'page', 1, self::MAX_PAGE, 1, $errors);
        $perPage = QueryReader::entero(
            $query,
            'per_page',
            1,
            $this->settings->usersPerPageMax,
            $this->settings->usersPerPageDefault,
            $errors,
        );

        $rol = QueryReader::texto($query, 'rol', $errors);
        if ($rol !== null && !Roles::esValido($rol)) {
            $errors['rol'] = 'El rol no es válido. Valores permitidos: ' . implode(', ', Roles::TODOS) . '.';
        }

        $estado = null;
        if ($verEstado) {
            $valor = QueryReader::texto($query, 'estado', $errors);
            if ($valor !== null) {
                $estado = match ($valor) {
                    'true' => true,
                    'false' => false,
                    default => null,
                };
                if ($estado === null) {
                    $errors['estado'] = 'El estado debe ser true o false.';
                }
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors, 'Los parámetros de la consulta no son válidos.');
        }

        return ['page' => $page, 'per_page' => $perPage, 'rol' => $rol, 'estado' => $estado];
    }
}
