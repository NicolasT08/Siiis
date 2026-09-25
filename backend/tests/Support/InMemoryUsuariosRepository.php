<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use Siiis\Repositories\UsuariosRepository;

final class InMemoryUsuariosRepository extends UsuariosRepository
{
    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->usuarios[(string) $id] ?? null;
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
