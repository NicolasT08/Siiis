<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección Sesiones (spec 8.2 y 10.13.6). `token` guarda el hash SHA-256 del jti,
 * nunca el JWT.
 */
class SesionesRepository
{
    public function __construct(private readonly Mongo $mongo)
    {
    }

    public function crear(
        ObjectId $usuarioId,
        string $tokenHash,
        DateTimeInterface $inicio,
        DateTimeInterface $expiracion,
        string $ip,
    ): void {
        $this->collection()->insertOne([
            'usuario_id' => $usuarioId,
            'token' => $tokenHash,
            'fecha_inicio' => new UTCDateTime($inicio),
            'fecha_expiracion' => new UTCDateTime($expiracion),
            'ip_acceso' => $ip,
            'activa' => true,
        ]);
    }

    /**
     * @return array<string, mixed>|null sesión activa y no vencida
     */
    public function findActiva(string $tokenHash, DateTimeInterface $ahora): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne([
            'token' => $tokenHash,
            'activa' => true,
            'fecha_expiracion' => ['$gt' => new UTCDateTime($ahora)],
        ]);
    }

    public function desactivar(string $tokenHash): void
    {
        $this->collection()->updateOne(['token' => $tokenHash], ['$set' => ['activa' => false]]);
    }

    public function desactivarTodas(ObjectId $usuarioId): void
    {
        $this->collection()->updateMany(
            ['usuario_id' => $usuarioId, 'activa' => true],
            ['$set' => ['activa' => false]],
        );
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::SESIONES);
    }
}
