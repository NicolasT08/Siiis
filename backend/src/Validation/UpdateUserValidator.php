<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Security\Roles;
use Siiis\Support\ApiException;

/**
 * PATCH /users/{id} (DEC-B27, DEC-B28): edición parcial de los campos de perfil y, opcionalmente, `rol`
 * y `estado`. Valida forma y lista blanca de roles; los permisos los decide UsersService. Otros campos
 * se ignoran.
 */
final class UpdateUserValidator
{
    public function __construct(private readonly PerfilValidator $perfil)
    {
    }

    /**
     * @return array{perfil: array<string, string|int|null>, rol: string|null, estado: bool|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $body = is_array($body) ? $body : [];
        $errors = [];

        $rol = null;
        if (array_key_exists('rol', $body)) {
            $rol = InputReader::string($body, 'rol', $errors);
            if (!isset($errors['rol']) && !Roles::esValido($rol)) {
                $errors['rol'] = 'El rol no es válido. Valores permitidos: ' . implode(', ', Roles::TODOS) . '.';
            }
        }

        // Booleano JSON estricto: "true", 1 o un objeto no se aceptan.
        $estado = null;
        if (array_key_exists('estado', $body)) {
            if (is_bool($body['estado'])) {
                $estado = $body['estado'];
            } else {
                $errors['estado'] = 'El estado debe ser true o false.';
            }
        }

        $perfil = $this->perfil->validate($body, true, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($perfil === [] && $rol === null && $estado === null) {
            throw ApiException::validation([], sprintf(
                'Envía al menos un campo para actualizar: %s, rol o estado.',
                implode(', ', PerfilValidator::CAMPOS),
            ));
        }

        return ['perfil' => $perfil, 'rol' => $rol, 'estado' => $estado];
    }
}
