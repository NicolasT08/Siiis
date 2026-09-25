<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/**
 * Acceso a la colección Usuarios (spec 8.2).
 */
class UsuariosRepository
{
    /** Campos necesarios para la sesión y el perfil resumido. */
    private const PROJECTION_SESION = [
        'credencial_id' => 1,
        'nombre' => 1,
        'apellido' => 1,
        'rol' => 1,
        'foto_perfil' => 1,
        'estado' => 1,
    ];

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id], ['projection' => self::PROJECTION_SESION]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredencialId(ObjectId $credencialId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(
            ['credencial_id' => $credencialId],
            ['projection' => self::PROJECTION_SESION],
        );
    }

    private function collection(): Collection
    {
        return $this->mongo->collection(Collections::USUARIOS);
    }
}
