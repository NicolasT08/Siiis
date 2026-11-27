<?php

declare(strict_types=1);

namespace Siiis\Validation;

use MongoDB\BSON\ObjectId;
use Siiis\Support\ObjectIdValidator;

/**
 * Lectura defensiva de la query string (spec 10.13.4): un parámetro repetido como arreglo
 * (`rol[$ne]=x`) es un error del campo; un parámetro vacío equivale a no enviarlo.
 */
final class QueryReader
{
    /** Tope de página: evita desbordar `skip` con números enormes. */
    public const MAX_PAGE = 10000;

    private function __construct()
    {
    }

    /**
     * @param array<mixed>          $query
     * @param array<string, string> $errors
     */
    public static function entero(array $query, string $campo, int $min, int $max, int $default, array &$errors): int
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
    public static function texto(array $query, string $campo, array &$errors): ?string
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

    /**
     * `true` o `false` literales; null si no viene.
     *
     * @param array<mixed>          $query
     * @param array<string, string> $errors
     */
    public static function booleano(array $query, string $campo, array &$errors): ?bool
    {
        $valor = self::texto($query, $campo, $errors);
        if ($valor === null) {
            return null;
        }
        $bool = match ($valor) {
            'true' => true,
            'false' => false,
            default => null,
        };
        if ($bool === null) {
            $errors[$campo] = sprintf('El parámetro %s debe ser true o false.', $campo);
        }

        return $bool;
    }

    /**
     * Valor de una lista blanca; null si no viene.
     *
     * @param array<mixed>          $query
     * @param list<string>          $permitidos
     * @param array<string, string> $errors
     */
    public static function opcion(array $query, string $campo, array $permitidos, array &$errors): ?string
    {
        $valor = self::texto($query, $campo, $errors);
        if ($valor !== null && !in_array($valor, $permitidos, true)) {
            $errors[$campo] = sprintf('Valor no válido. Valores permitidos: %s.', implode(', ', $permitidos));

            return null;
        }

        return $valor;
    }

    /**
     * Identificador de MongoDB (24 caracteres hexadecimales); null si no viene.
     *
     * @param array<mixed>          $query
     * @param array<string, string> $errors
     */
    public static function objectId(array $query, string $campo, array &$errors): ?ObjectId
    {
        $valor = self::texto($query, $campo, $errors);
        if ($valor === null) {
            return null;
        }
        $id = ObjectIdValidator::toObjectId($valor);
        if ($id === null) {
            $errors[$campo] = 'Debe ser un identificador válido (24 caracteres hexadecimales).';
        }

        return $id;
    }
}
