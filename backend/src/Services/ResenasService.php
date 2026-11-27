<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\ResenasRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\Paginacion;
use Siiis\Support\ResenaPresenter;

/**
 * Reseñas sobre el semillero (RF-10; DEC-B43 a DEC-B45, PROPUESTA):
 * - Cualquiera escribe. Con sesión, `usuario_id` y `nombre_visible` salen del perfil y la reseña sale
 *   `visible`; sin sesión, `usuario_id = null` y queda `oculta` hasta que un moderador la apruebe.
 * - Los visitantes y los integrantes solo ven las visibles; los moderadores ven todas.
 */
class ResenasService
{
    public function __construct(
        private readonly ResenasRepository $resenas,
        private readonly UsuariosRepository $usuarios,
        private readonly Clock $clock,
    ) {
    }

    /**
     * GET /reviews. `$query['estado']` ya viene en null para quien no modera (ListadoQueryValidator).
     *
     * @param array{page: int, per_page: int, estado: string|null} $query
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int,
     *               total_pages: int}, resumen: array{promedio: float|null, calificaciones: int}}
     */
    public function listar(array $query, ?string $actorRol): array
    {
        $estado = Roles::esModeradorResenas($actorRol) ? $query['estado'] : ResenasRepository::ESTADO_VISIBLE;
        ['items' => $items, 'total' => $total] = $this->resenas->paginar($estado, $query['page'], $query['per_page']);
        $resumen = $this->resenas->resumenVisibles();

        return [
            'data' => array_map(static fn (array $r): array => ResenaPresenter::presentar($r), $items),
            'meta' => Paginacion::meta($query['page'], $query['per_page'], $total),
            'resumen' => [
                'promedio' => $resumen['promedio'] !== null ? round($resumen['promedio'], 1) : null,
                'calificaciones' => $resumen['calificaciones'],
            ],
        ];
    }

    /**
     * POST /reviews (DEC-B43). `$actorId` es null para visitantes anónimos.
     *
     * @param array{nombre_visible: string|null, comentario: string, calificacion: int|null} $input
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 401 si la sesión apunta a un perfil que ya no existe
     */
    public function crear(array $input, ?string $actorId): array
    {
        $usuarioId = null;
        $nombre = $input['nombre_visible'] ?? '';
        $estado = ResenasRepository::ESTADO_OCULTA;

        if ($actorId !== null) {
            $usuarioId = ObjectIdValidator::toObjectId($actorId) ?? throw ApiException::unauthorized();
            $usuario = $this->usuarios->findById($usuarioId) ?? throw ApiException::unauthorized();
            $nombre = trim(sprintf(
                '%s %s',
                is_string($usuario['nombre'] ?? null) ? $usuario['nombre'] : '',
                is_string($usuario['apellido'] ?? null) ? $usuario['apellido'] : '',
            ));
            $estado = ResenasRepository::ESTADO_VISIBLE;
        }

        $resena = [
            '_id' => new ObjectId(),
            'usuario_id' => $usuarioId,
            'nombre_visible' => $nombre,
            'comentario' => $input['comentario'],
            'calificacion' => $input['calificacion'],
            'estado' => $estado,
            'fecha' => new UTCDateTime($this->clock->now()),
        ];
        $this->resenas->insertar($resena);

        return ResenaPresenter::presentar($resena);
    }

    /**
     * PATCH /reviews/{id} (DEC-B44). La ruta ya exige rol de moderador.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function cambiarEstado(string $id, string $estado): array
    {
        $oid = ObjectIdValidator::toObjectId($id) ?? throw self::noEncontrada();
        if (!$this->resenas->cambiarEstado($oid, $estado)) {
            throw self::noEncontrada();
        }

        return ResenaPresenter::presentar($this->resenas->findById($oid) ?? throw self::noEncontrada());
    }

    /**
     * DELETE /reviews/{id} (DEC-B44): borrado físico.
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function eliminar(string $id): void
    {
        $oid = ObjectIdValidator::toObjectId($id) ?? throw self::noEncontrada();
        if (!$this->resenas->eliminar($oid)) {
            throw self::noEncontrada();
        }
    }

    private static function noEncontrada(): ApiException
    {
        return ApiException::notFound('La reseña no existe.');
    }
}
