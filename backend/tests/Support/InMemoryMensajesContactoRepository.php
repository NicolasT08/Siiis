<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\MensajesContactoRepository;

/**
 * Doble de MensajesContactoRepository sobre InMemoryDatabase.
 */
final class InMemoryMensajesContactoRepository extends MensajesContactoRepository
{
    public bool $fallarAlInsertar = false;

    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function paginar(?string $estado, int $page, int $perPage): array
    {
        $items = array_values(array_filter(
            $this->db->mensajes,
            static fn (array $m): bool => $estado === null || $m['estado'] === $estado,
        ));
        usort($items, static fn (array $a, array $b): int => [self::ms($b['fecha']), (string) $b['_id']]
            <=> [self::ms($a['fecha']), (string) $a['_id']]);

        return ['items' => array_slice($items, ($page - 1) * $perPage, $perPage), 'total' => count($items)];
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->mensajes[(string) $id] ?? null;
    }

    public function insertar(array $mensaje): void
    {
        if ($this->fallarAlInsertar) {
            throw new \RuntimeException('MongoDB caído (simulado)');
        }
        $this->db->mensajes[(string) $mensaje['_id']] = $mensaje;
    }

    public function cambiarEstado(ObjectId $id, string $estado): bool
    {
        if (!isset($this->db->mensajes[(string) $id])) {
            return false;
        }
        $this->db->mensajes[(string) $id]['estado'] = $estado;

        return true;
    }

    private static function ms(mixed $fecha): int
    {
        return $fecha instanceof UTCDateTime ? (int) (string) $fecha : 0;
    }
}
