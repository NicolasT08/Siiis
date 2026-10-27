<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use Siiis\Repositories\UsuariosRepository;

final class InMemoryUsuariosRepository extends UsuariosRepository
{
    public bool $fallarAlActualizar = false;

    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->usuarios[(string) $id] ?? null;
    }

    public function findPerfilById(ObjectId $id): ?array
    {
        return $this->db->usuarios[(string) $id] ?? null;
    }

    public function paginar(?string $rol, ?bool $estado, int $page, int $perPage): array
    {
        $items = array_values(array_filter(
            $this->db->usuarios,
            static fn (array $u): bool => ($rol === null || $u['rol'] === $rol)
                && ($estado === null || $u['estado'] === $estado),
        ));
        // Aproxima la collation "es" de MongoDB para nombres sin tildes.
        usort($items, static fn (array $a, array $b): int => [
            mb_strtolower((string) $a['apellido']),
            mb_strtolower((string) $a['nombre']),
            (string) $a['_id'],
        ] <=> [
            mb_strtolower((string) $b['apellido']),
            mb_strtolower((string) $b['nombre']),
            (string) $b['_id'],
        ]);

        return ['items' => array_slice($items, ($page - 1) * $perPage, $perPage), 'total' => count($items)];
    }

    public function actualizar(ObjectId $id, array $set): void
    {
        if ($this->fallarAlActualizar) {
            throw new \RuntimeException('Fallo simulado de MongoDB');
        }
        if (isset($this->db->usuarios[(string) $id])) {
            $this->db->usuarios[(string) $id] = $set + $this->db->usuarios[(string) $id];
        }
    }

    public function contarSuperadministradoresActivos(): int
    {
        return count(array_filter(
            $this->db->usuarios,
            static fn (array $u): bool => $u['rol'] === 'superadministrador' && $u['estado'] === true,
        ));
    }

    public function findByCredencialId(ObjectId $credencialId): ?array
    {
        foreach ($this->db->usuarios as $doc) {
            if ((string) $doc['credencial_id'] === (string) $credencialId) {
                return $doc;
            }
        }

        return null;
    }
}
