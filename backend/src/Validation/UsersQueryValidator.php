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
    public const MAX_PAGE = 10000;

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

        $page = self::entero($query, 'page', 1, self::MAX_PAGE, 1, $errors);
        $perPage = self::entero(
            $query,
            'per_page',
            1,
            $this->settings->usersPerPageMax,
            $this->settings->usersPerPageDefault,
            $errors,
        );

        $rol = self::texto($query, 'rol', $errors);
        if ($rol !== null && !Roles::esValido($rol)) {
            $errors['rol'] = 'El rol no es válido. Valores permitidos: ' . implode(', ', Roles::TODOS) . '.';
        }

        $estado = null;
        if ($verEstado) {
            $valor = self::texto($query, 'estado', $errors);
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

    /**
     * @param array<mixed>          $query
     * @param array<string, string> $errors
     */
    private static function entero(array $query, string $campo, int $min, int $max, int $default, array &$errors): int
    {
        $valor = self::texto($query, $campo, $errors);
        if ($valor === null) {
            return $default;
        }
        if (!ctype_digit($valor) || strlen($valor) > 6 || (int) $valor < $min || (int) $valor > $max) {
            $errors[$campo] = sprintf('Debe ser un número entero entre %d y %d.', $min, $max);

            return $default;
        }

        return (int) $valor;
    }

    /**
     * Texto sin espacios alrededor, o null si el parámetro no viene o viene vacío.
     *
     * @param array<mixed>          $query
     * @param array<string, string> $errors
     */
    private static function texto(array $query, string $campo, array &$errors): ?string
    {
        if (!array_key_exists($campo, $query)) {
            return null;
        }
        $valor = $query[$campo];
        if (!is_string($valor)) {
            $errors[$campo] = 'Este parámetro debe ser texto.';

            return null;
        }
        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }
}
