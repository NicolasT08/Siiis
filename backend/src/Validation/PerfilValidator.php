<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;

/**
 * Campos de perfil de `Usuarios` (spec 8.2) con los límites de DEC-B21. Lo comparten el registro
 * (completo) y la edición de perfil (parcial). Solo acepta escalares (antiinyección NoSQL).
 */
final class PerfilValidator
{
    /** Campos de perfil editables; `rol` y `estado` se validan aparte. */
    public const CAMPOS = ['nombre', 'apellido', 'programa_academico', 'semestre', 'biografia', 'telefono'];

    private const OBLIGATORIOS = ['nombre', 'apellido'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Completo: `nombre` y `apellido` obligatorios; los opcionales ausentes quedan en null.
     * Parcial: solo devuelve los campos presentes; un opcional en null o "" se borra (null).
     *
     * @param array<string, string> $errors se completa con los errores por campo
     *
     * @return array<string, string|int|null>
     */
    public function validate(mixed $body, bool $parcial, array &$errors): array
    {
        $body = is_array($body) ? $body : [];
        $perfil = [];

        foreach (self::CAMPOS as $campo) {
            $presente = array_key_exists($campo, $body);
            if ($parcial && !$presente) {
                continue;
            }

            if (in_array($campo, self::OBLIGATORIOS, true)) {
                $valor = InputReader::string($body, $campo, $errors);
                if (!isset($errors[$campo]) && mb_strlen($valor) > $this->settings->perfilNombreMaxLength) {
                    $errors[$campo] = $this->demasiadoLargo($this->settings->perfilNombreMaxLength);
                }
                $perfil[$campo] = $valor;

                continue;
            }

            $perfil[$campo] = match ($campo) {
                'semestre' => $this->semestre($body[$campo] ?? null, $errors),
                'telefono' => $this->telefono($body[$campo] ?? null, $errors),
                'programa_academico' => $this->textoOpcional(
                    $body[$campo] ?? null,
                    $campo,
                    $this->settings->perfilProgramaMaxLength,
                    $errors,
                ),
                default => $this->textoOpcional(
                    $body[$campo] ?? null,
                    $campo,
                    $this->settings->perfilBiografiaMaxLength,
                    $errors,
                ),
            };
        }

        return $perfil;
    }

    /**
     * @param array<string, string> $errors
     */
    private function textoOpcional(mixed $valor, string $campo, int $max, array &$errors): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor)) {
            $errors[$campo] = 'Este campo debe ser texto.';

            return null;
        }
        if (!InputReader::utf8($valor)) {
            $errors[$campo] = InputReader::MENSAJE_UTF8;

            return null;
        }

        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        if (mb_strlen($valor) > $max) {
            $errors[$campo] = $this->demasiadoLargo($max);
        }

        return $valor;
    }

    /**
     * @param array<string, string> $errors
     */
    private function semestre(mixed $valor, array &$errors): ?int
    {
        if ($valor === null) {
            return null;
        }
        if (!is_int($valor) || $valor < 1 || $valor > $this->settings->perfilSemestreMax) {
            $errors['semestre'] = sprintf(
                'El semestre debe ser un número entero entre 1 y %d.',
                $this->settings->perfilSemestreMax,
            );

            return null;
        }

        return $valor;
    }

    /**
     * Se guarda sin espacios, guiones, puntos ni paréntesis; admite un "+" inicial.
     *
     * @param array<string, string> $errors
     */
    private function telefono(mixed $valor, array &$errors): ?string
    {
        $texto = $this->textoOpcional($valor, 'telefono', PHP_INT_MAX, $errors);
        if ($texto === null) {
            return null;
        }

        $telefono = (string) preg_replace('/[\s\-.()]/u', '', $texto);
        $patron = sprintf(
            '/^\+?\d{%d,%d}$/',
            $this->settings->perfilTelefonoMinDigits,
            $this->settings->perfilTelefonoMaxDigits,
        );
        if (preg_match($patron, $telefono) !== 1) {
            $errors['telefono'] = sprintf(
                'El teléfono debe tener entre %d y %d dígitos (se admite "+" al inicio).',
                $this->settings->perfilTelefonoMinDigits,
                $this->settings->perfilTelefonoMaxDigits,
            );

            return null;
        }

        return $telefono;
    }

    private function demasiadoLargo(int $max): string
    {
        return sprintf('Máximo %d caracteres.', $max);
    }
}
