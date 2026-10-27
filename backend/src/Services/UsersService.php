<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\UsuarioPresenter;

/**
 * Consulta y edición de integrantes (RF-08, RF-15, spec 10.8). Vista pública o de administrador según
 * quién consulta (DEC-B19); el público solo ve integrantes activos (DEC-B20).
 */
class UsersService
{
    public function __construct(
        private readonly UsuariosRepository $usuarios,
        private readonly CredencialesRepository $credenciales,
        private readonly SesionesRepository $sesiones,
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

    /**
     * PATCH /users/{id} (DEC-B17, DEC-B27). Cada quien edita su perfil; los administradores, además,
     * el de los roles que gestionan y su `rol`. Cambiar el rol cierra las sesiones del usuario.
     *
     * @param array{perfil: array<string, string|int|null>, rol: string|null} $input
     *
     * @return array<string, mixed> vista de administrador del usuario actualizado
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND, 409 LAST_SUPERADMIN
     */
    public function actualizar(string $id, string $actorId, string $actorRol, array $input): array
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        // Se compara el id normalizado: el hex de la URL puede venir en mayúsculas.
        $esPropio = $objectId !== null && (string) $objectId === $actorId;
        // Antes de buscar: un integrante no distingue entre "no existe" y "no es tuyo".
        if (!$esPropio && !self::esAdmin($actorRol)) {
            throw ApiException::forbidden('Solo puedes editar tu propio perfil.');
        }

        $usuario = $objectId === null ? null : $this->usuarios->findPerfilById($objectId);
        if ($objectId === null || $usuario === null) {
            throw self::noEncontrado();
        }

        $rolActual = (string) ($usuario['rol'] ?? '');
        $nuevoRol = $input['rol'] !== null && $input['rol'] !== $rolActual ? $input['rol'] : null;

        if ($esPropio) {
            if ($nuevoRol !== null) {
                throw ApiException::forbidden('No puedes cambiar tu propio rol.');
            }
        } else {
            if (!Roles::puedeGestionar($actorRol, $rolActual)) {
                throw ApiException::forbidden(sprintf('No puedes editar usuarios con el rol %s.', $rolActual));
            }
            if ($nuevoRol !== null && !Roles::puedeGestionar($actorRol, $nuevoRol)) {
                throw ApiException::forbidden(sprintf('No puedes asignar el rol %s.', $nuevoRol));
            }
        }

        if (
            $nuevoRol !== null
            && $rolActual === Roles::SUPERADMINISTRADOR
            && ($usuario['estado'] ?? null) === true
            && $this->usuarios->contarSuperadministradoresActivos() <= 1
        ) {
            throw self::ultimoSuperadministrador();
        }

        $set = $input['perfil'];
        if ($nuevoRol !== null) {
            $set['rol'] = $nuevoRol;
        }
        if ($set !== []) {
            $this->usuarios->actualizar($objectId, $set);
        }
        if ($nuevoRol !== null) {
            // El rol viaja en el JWT: se cierran las sesiones para que no quede desactualizado.
            $this->sesiones->desactivarTodas($objectId);
        }

        return $this->obtener((string) $objectId, $actorId, $actorRol);
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

    private static function ultimoSuperadministrador(): ApiException
    {
        return new ApiException(
            409,
            'LAST_SUPERADMIN',
            'No se puede quitar el rol al último superadministrador activo.',
        );
    }
}
