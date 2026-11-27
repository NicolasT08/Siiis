<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\ResenasRepository;

/**
 * Doble de ResenasRepository sobre InMemoryDatabase (orden por fecha y _id descendentes).
 */
final class InMemoryResenasRepository extends ResenasRepository
{
    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function paginar(?string $estado, int $page, int $perPage): array
    {
        $items = array_values(array_filter(
            $this->db->resenas,
            static fn (array $r): bool => $estado === null || $r['estado'] === $estado,
        ));
        usort($items, static fn (array $a, array $b): int => [self::ms($b['fecha']), (string) $b['_id']]
            <=> [self::ms($a['fecha']), (string) $a['_id']]);

        return ['items' => array_slice($items, ($page - 1) * $perPage, $perPage), 'total' => count($items)];
    }

    public function resumenVisibles(): array
    {
        $notas = [];
        foreach ($this->db->resenas as $r) {
            if ($r['estado'] === self::ESTADO_VISIBLE && is_int($r['calificacion'] ?? null)) {
                $notas[] = $r['calificacion'];
            }
        }

        return [
            'promedio' => $notas === [] ? null : array_sum($notas) / count($notas),
            'calificaciones' => count($notas),
        ];
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->resenas[(string) $id] ?? null;
    }

    public function insertar(array $resena): void
    {
        $this->db->resenas[(string) $resena['_id']] = $resena;
    }

    public function cambiarEstado(ObjectId $id, string $estado): bool
    {
        if (!isset($this->db->resenas[(string) $id])) {
            return false;
        }
        $this->db->resenas[(string) $id]['estado'] = $estado;

        return true;
    }

    public function eliminar(ObjectId $id): bool
    {
        if (!isset($this->db->resenas[(string) $id])) {
            return false;
        }
        unset($this->db->resenas[(string) $id]);

        return true;
    }

    private static function ms(mixed $fecha): int
    {
        return $fecha instanceof UTCDateTime ? (int) (string) $fecha : 0;
    }
}
