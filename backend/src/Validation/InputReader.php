<?php

declare(strict_types=1);

namespace Siiis\Validation;

/**
 * Lectura defensiva de campos de texto (antiinyección NoSQL, spec 10.13.4 y 18): solo acepta
 * escalares; arrays u objetos (p. ej. {"$ne": ""}) se reportan como error del campo.
 */
final class InputReader
{
    /**
     * @param array<string, string> $errors se completa con el error del campo, si lo hay
     */
    public static function string(mixed $body, string $field, array &$errors, bool $trim = true): string
    {
        if (!is_array($body) || !array_key_exists($field, $body) || $body[$field] === null) {
            $errors[$field] = 'Este campo es obligatorio.';

            return '';
        }

        $value = $body[$field];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $errors[$field] = 'Este campo debe ser texto.';

            return '';
        }

        $value = (string) $value;
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            $errors[$field] = 'Este campo es obligatorio.';
        }

        return $value;
    }

    /**
     * Correo normalizado (sin espacios, minúsculas) y con formato válido.
     *
     * @param array<string, string> $errors
     */
    public static function correo(mixed $body, array &$errors): string
    {
        $correo = mb_strtolower(self::string($body, 'correo', $errors));
        if (isset($errors['correo'])) {
            return '';
        }
        if (mb_strlen($correo) > 254 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['correo'] = 'El correo no tiene un formato válido.';
        }

        return $correo;
    }
}
