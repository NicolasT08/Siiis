<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\CredencialesRepository;

final class InMemoryCredencialesRepository extends CredencialesRepository
{
    /** @var list<string> filtros recibidos, para comprobar que no llegan arrays */
    public array $consultas = [];

    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function findByCorreo(string $correo): ?array
    {
        $this->consultas[] = $correo;
        foreach ($this->db->credenciales as $doc) {
            if ($doc['correo'] === $correo) {
                return $doc;
            }
        }

        return null;
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->credenciales[(string) $id] ?? null;
    }

    /** Veces que se pidió un lote de cuentas (para comprobar que no hay N+1). */
    public int $consultasCuentas = 0;

    public function findCuentasByIds(array $ids): array
    {
        ++$this->consultasCuentas;
        $cuentas = [];
        foreach ($ids as $id) {
            if (isset($this->db->credenciales[(string) $id])) {
                $cuentas[(string) $id] = $this->db->credenciales[(string) $id];
            }
        }

        return $cuentas;
    }

    public function existsByCorreo(string $correo): bool
    {
        $this->consultas[] = $correo;

        return $this->findByCorreo($correo) !== null;
    }

    public function findByTokenRecuperacion(string $tokenHash): ?array
    {
        foreach ($this->db->credenciales as $doc) {
            if (($doc['token_recuperacion'] ?? null) === $tokenHash) {
                return $doc;
            }
        }

        return null;
    }

    public function registrarIntentoFallido(ObjectId $id): int
    {
        $intentos = (int) $this->db->credenciales[(string) $id]['intentos_fallidos'] + 1;
        $this->db->credenciales[(string) $id]['intentos_fallidos'] = $intentos;

        return $intentos;
    }

    public function registrarAccesoExitoso(ObjectId $id, DateTimeInterface $fecha, ?string $nuevoHash = null): void
    {
        $this->db->credenciales[(string) $id]['intentos_fallidos'] = 0;
        $this->db->credenciales[(string) $id]['ultimo_acceso'] = new UTCDateTime($fecha);
        if ($nuevoHash !== null) {
            $this->db->credenciales[(string) $id]['password'] = $nuevoHash;
        }
    }

    public function guardarTokenRecuperacion(ObjectId $id, string $tokenHash, DateTimeInterface $expiracion): void
    {
        $this->db->credenciales[(string) $id]['token_recuperacion'] = $tokenHash;
        $this->db->credenciales[(string) $id]['token_expiracion'] = new UTCDateTime($expiracion);
    }

    public function cambiarPassword(ObjectId $id, string $hash): void
    {
        $this->db->credenciales[(string) $id]['password'] = $hash;
        $this->db->credenciales[(string) $id]['token_recuperacion'] = null;
        $this->db->credenciales[(string) $id]['token_expiracion'] = null;
    }
}
