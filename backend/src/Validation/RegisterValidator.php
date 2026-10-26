<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;

/**
 * POST /auth/register (RF-01, RF-02; DEC-B16): {"correo", "password", "rol", "nombre", "apellido",
 * "programa_academico"?, "semestre"?, "biografia"?, "telefono"?}. Valida forma y lista blanca de roles;
 * si el actor puede asignar ese rol lo decide UserAccountService.
 */
final class RegisterValidator
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PerfilValidator $perfil,
    ) {
    }

    /**
     * @return array{correo: string, password: string, rol: string, perfil: array<string, string|int|null>}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $errors = [];
        $correo = InputReader::correo($body, $errors);

        $password = InputReader::string($body, 'password', $errors, false);
        if (!isset($errors['password'])) {
            $passwordError = PasswordRules::error($password, $this->settings);
            if ($passwordError !== null) {
                $errors['password'] = $passwordError;
            }
        }

        $rol = InputReader::string($body, 'rol', $errors);
        if (!isset($errors['rol']) && !Roles::esValido($rol)) {
            $errors['rol'] = 'El rol no es válido. Valores permitidos: ' . implode(', ', Roles::TODOS) . '.';
        }

        $perfil = $this->perfil->validate($body, false, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['correo' => $correo, 'password' => $password, 'rol' => $rol, 'perfil' => $perfil];
    }
}
