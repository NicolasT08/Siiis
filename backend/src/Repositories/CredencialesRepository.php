<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección Credenciales (spec 8.2). Los parámetros llegan ya validados y tipados.
 */
class CredencialesRepository
{
    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCorreo(string $correo): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['correo' => $correo]);
    }

    /**
     * Datos de cuenta para la vista de administrador de varios usuarios en una sola consulta (evita N+1).
     *
     * @param list<ObjectId> $ids
     *
     * @return array<string, array<string, mixed>> indexado por el _id en texto
     */
    public function findCuentasByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $cursor = $this->collection()->find(
            ['_id' => ['$in' => $ids]],
            ['projection' => ['correo' => 1, 'estado_cuenta' => 1, 'ultimo_acceso' => 1]],
        );

        $cuentas = [];
        foreach ($cursor as $doc) {
            /** @var array<string, mixed> $doc */
            $cuentas[(string) $doc['_id']] = $doc;
        }

        return $cuentas;
    }

    public function existsByCorreo(string $correo): bool
    {
        return $this->collection()->countDocuments(['correo' => $correo], ['limit' => 1]) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTokenRecuperacion(string $tokenHash): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['token_recuperacion' => $tokenHash]);
    }

    /**
     * Incrementa intentos_fallidos y devuelve el nuevo valor.
     */
    public function registrarIntentoFallido(ObjectId $id): int
    {
        /** @var array<string, mixed>|null $doc */
        $doc = $this->collection()->findOneAndUpdate(
            ['_id' => $id],
            ['$inc' => ['intentos_fallidos' => 1]],
            [
                'projection' => ['intentos_fallidos' => 1],
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ],
        );

        return is_int($doc['intentos_fallidos'] ?? null) ? $doc['intentos_fallidos'] : 0;
    }

    /**
     * Login exitoso: reinicia intentos_fallidos, actualiza ultimo_acceso y, si hace falta, el hash.
     */
    public function registrarAccesoExitoso(ObjectId $id, DateTimeInterface $fecha, ?string $nuevoHash = null): void
    {
        $set = ['intentos_fallidos' => 0, 'ultimo_acceso' => new UTCDateTime($fecha)];
        if ($nuevoHash !== null) {
            $set['password'] = $nuevoHash;
        }
        $this->collection()->updateOne(['_id' => $id], ['$set' => $set]);
    }

    public function guardarTokenRecuperacion(ObjectId $id, string $tokenHash, DateTimeInterface $expiracion): void
    {
        $this->collection()->updateOne(['_id' => $id], ['$set' => [
            'token_recuperacion' => $tokenHash,
            'token_expiracion' => new UTCDateTime($expiracion),
        ]]);
    }

    /**
     * Guarda la nueva contraseña e invalida el token de recuperación (un solo uso).
     */
    public function cambiarPassword(ObjectId $id, string $hash): void
    {
        $this->collection()->updateOne(['_id' => $id], ['$set' => [
            'password' => $hash,
            'token_recuperacion' => null,
            'token_expiracion' => null,
        ]]);
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::CREDENCIALES);
    }
}
