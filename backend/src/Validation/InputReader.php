<?php

declare(strict_types=1);

namespace Siiis\Validation;

/**
 * Lectura defensiva de campos de texto (antiinyección NoSQL, spec 10.13.4 y 18): solo acepta
 * escalares; arrays u objetos (p. ej. {"$ne": ""}) se reportan como error del campo.
 */
final class InputReader
{
    public const MENSAJE_UTF8 = 'El texto tiene caracteres no válidos. Envíalo con codificación UTF-8.';

    /**
     * Un cuerpo JSON siempre es UTF-8 válido (json_decode lo exige), pero los campos de un formulario
     * `multipart/form-data` llegan sin revisar: un texto en Latin-1 haría fallar a MongoDB con un 500.
     */
    public static function utf8(string $valor): bool
    {
        return mb_check_encoding($valor, 'UTF-8');
    }

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
        if (!self::utf8($value)) {
            $errors[$field] = self::MENSAJE_UTF8;

            return '';
        }
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            $errors[$field] = 'Este campo es obligatorio.';
        }

        return $value;
    }

    /**
     * Texto obligatorio de hasta `$max` caracteres. Con `$unaLinea`, los saltos de línea y espacios repetidos
     * se reducen a un espacio (nombres y asuntos que terminan en cabeceras de correo, DEC-B46).
     *
     * @param array<string, string> $errors
     */
    public static function texto(mixed $body, string $field, int $max, array &$errors, bool $unaLinea = false): string
    {
        $valor = self::string($body, $field, $errors);
        if (isset($errors[$field])) {
            return '';
        }
        if ($unaLinea) {
            $valor = (string) preg_replace('/\s+/u', ' ', $valor);
        }
        if (mb_strlen($valor) > $max) {
            $errors[$field] = sprintf('Máximo %d caracteres.', $max);
        }

        return $valor;
    }

    /**
     * Valor obligatorio de una lista blanca.
     *
     * @param list<string>          $permitidos
     * @param array<string, string> $errors
     */
    public static function opcion(mixed $body, string $field, array $permitidos, array &$errors): string
    {
        $valor = is_array($body) ? ($body[$field] ?? null) : null;
        if (!is_string($valor) || !in_array($valor, $permitidos, true)) {
            $errors[$field] = sprintf('Valor no válido. Valores permitidos: %s.', implode(', ', $permitidos));

            return '';
        }

        return $valor;
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
