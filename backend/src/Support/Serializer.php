<?php

declare(strict_types=1);

namespace Siiis\Support;

use DateTimeInterface;
use DateTimeZone;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Convierte documentos de MongoDB a datos JSON seguros (spec 10.13.3):
 * ObjectId → string, UTCDateTime → ISO 8601 UTC, `_id` → `id`, y elimina campos sensibles.
 */
final class Serializer
{
    /** Campos que nunca salen en una respuesta. */
    public const SENSITIVE_FIELDS = ['password', 'token', 'token_recuperacion', 'token_expiracion', 'public_id'];

    public static function serialize(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }
        if ($value instanceof UTCDateTime) {
            return self::formatDate($value->toDateTime());
        }
        if ($value instanceof DateTimeInterface) {
            return self::formatDate($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SENSITIVE_FIELDS, true)) {
                continue;
            }
            $result[$key === '_id' ? 'id' : $key] = self::serialize($item);
        }

        return $result;
    }

    public static function formatDate(DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
