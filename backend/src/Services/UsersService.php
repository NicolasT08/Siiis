<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\UsuarioPresenter;

/**
 * Consulta de integrantes (RF-08, spec 10.8). Vista pública o de administrador según quién consulta
 * (DEC-B19); el público solo ve integrantes activos (DEC-B20).
 */
class UsersService
{
    public function __construct(
        private readonly UsuariosRepository $usuarios,
        private readonly CredencialesRepository $credenciales,
    ) {
    }

    /**
     * GET /users. `$actorRol` es null para visitantes anónimos.
     *
     * @param array{page: int, per_page: int, rol: string|null, estado: bool|null} $query
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public function listar(array $query, ?string $actorRol): array
    {
        $esAdmin = self::esAdmin($actorRol);
        $estado = $esAdmin ? $query['estado'] : true;

        ['items' => $items, 'total' => $total] = $this->usuarios->paginar(
            $query['rol'],
            $estado,
            $query['page'],
            $query['per_page'],
        );

        if ($esAdmin) {
            $cuentas = $this->credenciales->findCuentasByIds(self::credencialIds($items));
            $data = array_map(
                static fn (array $u): array => UsuarioPresenter::administrador(
                    $u,
                    $cuentas[(string) ($u['credencial_id'] ?? '')] ?? null,
                ),
                $items,
            );
        } else {
            $data = array_map(static fn (array $u): array => UsuarioPresenter::publico($u), $items);
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $query['page'],
                'per_page' => $query['per_page'],
                'total' => $total,
                'total_pages' => (int) ceil($total / $query['per_page']),
            ],
        ];
    }

    /**
     * GET /users/{id}. Los administradores y el propio usuario reciben la vista completa (DEC-B26).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND si el id no es válido, no existe o (vista pública) está inactivo
     */
    public function obtener(string $id, ?string $actorId, ?string $actorRol): array
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        $usuario = $objectId === null ? null : $this->usuarios->findPerfilById($objectId);
        if ($usuario === null) {
            throw self::noEncontrado();
        }

        $esPropio = $actorId !== null && $actorId === (string) $usuario['_id'];
        if (self::esAdmin($actorRol) || $esPropio) {
            $credencialIds = self::credencialIds([$usuario]);
            $cuenta = $credencialIds === []
                ? null
                : ($this->credenciales->findCuentasByIds($credencialIds)[(string) $credencialIds[0]] ?? null);

            return UsuarioPresenter::administrador($usuario, $cuenta);
        }

        if (($usuario['estado'] ?? null) !== true) {
            throw self::noEncontrado();
        }

        return UsuarioPresenter::publico($usuario);
    }

    private static function esAdmin(?string $actorRol): bool
    {
        return $actorRol !== null && Roles::esAdministrativo($actorRol);
    }

    /**
     * @param list<array<string, mixed>> $usuarios
     *
     * @return list<ObjectId>
     */
    private static function credencialIds(array $usuarios): array
    {
        $ids = [];
        foreach ($usuarios as $usuario) {
            if (($usuario['credencial_id'] ?? null) instanceof ObjectId) {
                $ids[] = $usuario['credencial_id'];
            }
        }

        return $ids;
    }

    private static function noEncontrado(): ApiException
    {
        return new ApiException(404, 'NOT_FOUND', 'El usuario no existe.');
    }
}
