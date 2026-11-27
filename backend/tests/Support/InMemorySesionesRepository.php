<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\SesionesRepository;

final class InMemorySesionesRepository extends SesionesRepository
{
    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function crear(
        ObjectId $usuarioId,
        string $tokenHash,
        DateTimeInterface $inicio,
        DateTimeInterface $expiracion,
        string $ip,
    ): void {
        $id = new ObjectId();
        $this->db->sesiones[(string) $id] = [
            '_id' => $id,
            'usuario_id' => $usuarioId,
            'token' => $tokenHash,
            'fecha_inicio' => new UTCDateTime($inicio),
            'fecha_expiracion' => new UTCDateTime($expiracion),
            'ip_acceso' => $ip,
            'activa' => true,
        ];
    }

    public function findActiva(string $tokenHash, DateTimeInterface $ahora): ?array
    {
        foreach ($this->db->sesiones as $doc) {
            $expira = $doc['fecha_expiracion'];
            if (
                $doc['token'] === $tokenHash && $doc['activa'] === true
                && $expira instanceof UTCDateTime && $expira->toDateTime() > $ahora
            ) {
                return $doc;
            }
        }

        return null;
    }

    public function desactivar(string $tokenHash): void
    {
        foreach ($this->db->sesiones as $id => $doc) {
            if ($doc['token'] === $tokenHash) {
                $this->db->sesiones[$id]['activa'] = false;
            }
        }
    }

    public function desactivarOtras(ObjectId $usuarioId, string $tokenHashActual): void
    {
        foreach ($this->db->sesiones as $id => $doc) {
            if ((string) $doc['usuario_id'] === (string) $usuarioId && $doc['token'] !== $tokenHashActual) {
                $this->db->sesiones[$id]['activa'] = false;
            }
        }
    }

    public function desactivarTodas(ObjectId $usuarioId): void
    {
        foreach ($this->db->sesiones as $id => $doc) {
            if ((string) $doc['usuario_id'] === (string) $usuarioId) {
                $this->db->sesiones[$id]['activa'] = false;
            }
        }
    }
}
