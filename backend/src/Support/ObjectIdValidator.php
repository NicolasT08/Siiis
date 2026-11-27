<?php

declare(strict_types=1);

namespace Siiis\Support;

use MongoDB\BSON\ObjectId;

/**
 * Valida identificadores antes de usarlos en consultas (spec 10.13.3).
 */
final class ObjectIdValidator
{
    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{24}\z/i', $id) === 1;
    }

    /**
     * Devuelve el ObjectId o null si el valor no es un identificador válido (nunca lanza).
     */
    public static function toObjectId(mixed $id): ?ObjectId
    {
        if ($id instanceof ObjectId) {
            return $id;
        }

        return self::isValid($id) ? new ObjectId($id) : null;
    }
}
