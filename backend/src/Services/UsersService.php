<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\Logger;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\UsuarioPresenter;
use Throwable;

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
        private readonly Clock $clock,
        private readonly CloudinaryService $cloudinary,
        private readonly Settings $settings,
        private readonly Logger $logger,
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
     * PATCH /users/{id} (DEC-B17, DEC-B22, DEC-B27, DEC-B28). Cada quien edita su perfil; los
     * administradores, además, el de los roles que gestionan, su `rol` y su `estado`. Cambiar el rol o
     * desactivar cierra las sesiones del usuario.
     *
     * @param array{perfil: array<string, string|int|null>, rol: string|null, estado: bool|null} $input
     *
     * @return array<string, mixed> vista de administrador del usuario actualizado
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND, 409 LAST_SUPERADMIN
     */
    public function actualizar(string $id, string $actorId, string $actorRol, array $input): array
    {
        [$objectId, $usuario, $esPropio] = $this->editable($id, $actorId, $actorRol);

        $rolActual = (string) ($usuario['rol'] ?? '');
        $nuevoRol = $input['rol'] !== null && $input['rol'] !== $rolActual ? $input['rol'] : null;
        $estadoActual = ($usuario['estado'] ?? null) === true;
        // Igual que con el rol: reenviar el estado actual no es un cambio (DELETE repetido es idempotente).
        $nuevoEstado = $input['estado'] !== null && $input['estado'] !== $estadoActual ? $input['estado'] : null;

        if ($esPropio) {
            if ($nuevoRol !== null) {
                throw ApiException::forbidden('No puedes cambiar tu propio rol.');
            }
            if ($nuevoEstado !== null) {
                throw ApiException::forbidden('No puedes cambiar tu propio estado.');
            }
        } elseif ($nuevoRol !== null && !Roles::puedeGestionar($actorRol, $nuevoRol)) {
            throw ApiException::forbidden(sprintf('No puedes asignar el rol %s.', $nuevoRol));
        }

        $desactiva = $nuevoEstado === false;
        if (
            ($nuevoRol !== null || $desactiva)
            && $rolActual === Roles::SUPERADMINISTRADOR
            && $estadoActual
            && $this->usuarios->contarSuperadministradoresActivos() <= 1
        ) {
            throw self::ultimoSuperadministrador();
        }

        $set = $input['perfil'];
        if ($nuevoRol !== null) {
            $set['rol'] = $nuevoRol;
        }
        if ($nuevoEstado !== null) {
            // Reactivar borra la fecha de salida y conserva fecha_ingreso (DEC-B22).
            $set['estado'] = $nuevoEstado;
            $set['fecha_salida'] = $desactiva ? new UTCDateTime($this->clock->now()) : null;
        }
        if ($set !== []) {
            $this->usuarios->actualizar($objectId, $set);
        }
        if ($nuevoRol !== null || $desactiva) {
            // El rol viaja en el JWT y un inactivo no debe seguir operando: se cierran sus sesiones.
            $this->sesiones->desactivarTodas($objectId);
        }

        return $this->obtener((string) $objectId, $actorId, $actorRol);
    }

    /**
     * DELETE /users/{id} (RF-09, DEC-B22): desactivación lógica por deserción, con las mismas reglas que
     * `PATCH {"estado": false}`. Repetirla sobre un inactivo no cambia nada (conserva su fecha_salida).
     *
     * @return array<string, mixed> vista de administrador del usuario desactivado
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND, 409 LAST_SUPERADMIN
     */
    public function desactivar(string $id, string $actorId, string $actorRol): array
    {
        return $this->actualizar($id, $actorId, $actorRol, ['perfil' => [], 'rol' => null, 'estado' => false]);
    }

    /**
     * POST /users/{id}/photo (RF-06, spec 10.13.8 y 13, DEC-B29): mismos permisos que editar el perfil.
     * Sube la nueva foto, guarda `{url, public_id}` y, solo entonces, borra la anterior. Si MongoDB falla,
     * borra la recién subida (compensación) para no dejar archivos huérfanos.
     *
     * @param string $path imagen ya validada por FileValidator (temporal de PHP)
     *
     * @return array<string, mixed> vista de administrador con la foto nueva
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND, 502 CLOUDINARY_UNAVAILABLE
     */
    public function cambiarFoto(string $id, string $actorId, string $actorRol, string $path): array
    {
        [$objectId, $usuario] = $this->editable($id, $actorId, $actorRol);
        $fotoAnterior = $usuario['foto_perfil'] ?? null;
        $anterior = is_array($fotoAnterior) ? ($fotoAnterior['public_id'] ?? null) : null;

        try {
            $foto = $this->cloudinary->upload(
                $path,
                $this->settings->cloudinaryPerfilesFolder,
                CloudinaryService::FORMATOS_IMAGEN,
                CloudinaryService::transformacionFotoPerfil($this->settings),
            );
        } catch (ArchivoRechazadoException $e) {
            $this->logger->warning('Cloudinary rechazó una foto de perfil', [
                'usuario_id' => (string) $objectId,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException(
                415,
                'UNSUPPORTED_MEDIA_TYPE',
                'La imagen está dañada o no es una imagen válida. Prueba con otra foto en JPG, PNG o WebP.',
            );
        } catch (CloudinaryException $e) {
            $this->logger->error('No se pudo subir la foto de perfil', [
                'usuario_id' => (string) $objectId,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException(
                502,
                'CLOUDINARY_UNAVAILABLE',
                'No se pudo guardar la imagen. Intenta de nuevo más tarde.',
            );
        }

        try {
            $this->usuarios->actualizar($objectId, ['foto_perfil' => $foto]);
        } catch (Throwable $e) {
            $this->borrarFoto($foto['public_id'], 'compensación tras fallo en MongoDB');

            throw $e;
        }

        if (is_string($anterior) && $anterior !== '' && $anterior !== $foto['public_id']) {
            $this->borrarFoto($anterior, 'reemplazo de foto de perfil');
        }

        return $this->obtener((string) $objectId, $actorId, $actorRol);
    }

    /**
     * DELETE /users/{id}/photo (RF-06, DEC-B74): el usuario quita su propia foto. Nadie quita la de otro, ni un
     * administrador (403). Primero deja `foto_perfil = null` y después borra el archivo en Cloudinary; si ese
     * borrado falla, queda en el log y la operación sigue (como al reemplazar la foto, DEC-B29). Sin foto, no hace
     * nada y responde igual.
     *
     * @return array<string, mixed> vista completa del propio usuario, con `foto_perfil = null`
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND
     */
    public function quitarFoto(string $id, string $actorId): array
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        if ($objectId === null || (string) $objectId !== $actorId) {
            throw ApiException::forbidden('Solo puedes quitar tu propia foto de perfil.');
        }
        $usuario = $this->usuarios->findPerfilById($objectId) ?? throw self::noEncontrado();

        $foto = $usuario['foto_perfil'] ?? null;
        if ($foto !== null) {
            $this->usuarios->actualizar($objectId, ['foto_perfil' => null]);
            $publicId = is_array($foto) ? ($foto['public_id'] ?? null) : null;
            if (is_string($publicId) && $publicId !== '') {
                $this->borrarFoto($publicId, 'el usuario quitó su foto de perfil');
            }
        }

        return $this->obtener((string) $objectId, $actorId, null);
    }

    /**
     * Usuario que `$actor` puede editar (DEC-B17): el propio o, para roles administrativos, uno de los
     * roles que gestiona. Las reglas sobre `rol` y `estado` las aplica `actualizar`.
     *
     * @return array{ObjectId, array<string, mixed>, bool} [id, perfil, esPropio]
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND
     */
    private function editable(string $id, string $actorId, string $actorRol): array
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

        $rol = (string) ($usuario['rol'] ?? '');
        if (!$esPropio && !Roles::puedeGestionar($actorRol, $rol)) {
            throw ApiException::forbidden(sprintf('No puedes editar usuarios con el rol %s.', $rol));
        }

        return [$objectId, $usuario, $esPropio];
    }

    /**
     * Borrado sin propagar errores: la operación principal ya terminó; un fallo solo deja un archivo
     * huérfano, que queda en el log para limpiarlo a mano.
     */
    private function borrarFoto(string $publicId, string $motivo): void
    {
        try {
            $this->cloudinary->delete($publicId);
        } catch (CloudinaryException $e) {
            $this->logger->error('No se pudo borrar una foto de Cloudinary', [
                'public_id' => $publicId,
                'motivo' => $motivo,
                'error' => $e->getMessage(),
            ]);
        }
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
            'No se puede quitar el rol ni desactivar al último superadministrador activo.',
        );
    }
}
