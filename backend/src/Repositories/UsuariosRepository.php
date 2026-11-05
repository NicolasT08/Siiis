<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Security\Roles;

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

    /** Perfil completo para GET /users: credencial_id solo sirve para unir con Credenciales. */
    private const PROJECTION_PERFIL = [
        'credencial_id' => 1,
        'nombre' => 1,
        'apellido' => 1,
        'rol' => 1,
        'programa_academico' => 1,
        'semestre' => 1,
        'foto_perfil' => 1,
        'biografia' => 1,
        'telefono' => 1,
        'estado' => 1,
        'fecha_ingreso' => 1,
        'fecha_salida' => 1,
    ];

    /** Datos mínimos para mostrar el autor y los integrantes de un documento (vista pública, DEC-B19). */
    private const PROJECTION_RESUMEN = [
        'nombre' => 1,
        'apellido' => 1,
        'rol' => 1,
        'foto_perfil' => 1,
        'estado' => 1,
    ];

    /** Orden alfabético en español: ignora tildes y mayúsculas ("Álvarez" antes que "Bernal"). */
    private const COLLATION_ES = ['locale' => 'es', 'strength' => 1];

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
    public function findPerfilById(ObjectId $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection()->findOne(['_id' => $id], ['projection' => self::PROJECTION_PERFIL]);
    }

    /**
     * Página de perfiles ordenada por apellido, nombre e _id (orden estable entre páginas).
     * Los filtros llegan validados: `$rol` de la lista blanca de roles; null = sin filtro.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginar(?string $rol, ?bool $estado, int $page, int $perPage): array
    {
        $filtro = [];
        if ($rol !== null) {
            $filtro['rol'] = $rol;
        }
        if ($estado !== null) {
            $filtro['estado'] = $estado;
        }

        $cursor = $this->collection()->find($filtro, [
            'projection' => self::PROJECTION_PERFIL,
            'sort' => ['apellido' => 1, 'nombre' => 1, '_id' => 1],
            'collation' => self::COLLATION_ES,
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = array_values($cursor->toArray());

        return ['items' => $items, 'total' => $this->collection()->countDocuments($filtro)];
    }

    /**
     * Resúmenes de varios usuarios en una sola consulta (spec 10.13.4: sin N+1), indexados por id.
     * Los ids que no existen simplemente no aparecen.
     *
     * @param list<ObjectId> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    public function findResumenesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $resumenes = [];
        foreach ($this->collection()->find(['_id' => ['$in' => $ids]], ['projection' => self::PROJECTION_RESUMEN]) as $usuario) {
            /** @var array<string, mixed> $usuario */
            $resumenes[(string) $usuario['_id']] = $usuario;
        }

        return $resumenes;
    }

    /**
     * `$set` llega validado: sus claves salen de listas blancas de campos de `Usuarios`.
     *
     * @param array<string, mixed> $set
     */
    public function actualizar(ObjectId $id, array $set): void
    {
        $this->collection()->updateOne(['_id' => $id], ['$set' => $set]);
    }

    public function contarSuperadministradoresActivos(): int
    {
        return $this->collection()->countDocuments(['rol' => Roles::SUPERADMINISTRADOR, 'estado' => true]);
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
