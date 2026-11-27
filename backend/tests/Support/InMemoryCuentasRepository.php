<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Siiis\Repositories\CuentasRepository;
use Siiis\Repositories\DuplicateKeyException;

/**
 * Simula la transacción: o se escriben los dos documentos o ninguno, y respeta el índice único de correo.
 */
final class InMemoryCuentasRepository extends CuentasRepository
{
    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function crear(array $credencial, array $usuario): void
    {
        foreach ($this->db->credenciales as $doc) {
            if ($doc['correo'] === $credencial['correo']) {
                throw new DuplicateKeyException('E11000 duplicate key error (correo)');
            }
        }

        $this->db->credenciales[(string) $credencial['_id']] = $credencial;
        $this->db->usuarios[(string) $usuario['_id']] = $usuario;
    }
}
