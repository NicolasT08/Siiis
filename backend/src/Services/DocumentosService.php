<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\DocumentoCriterios;
use Siiis\Repositories\DocumentosRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\DocumentoPresenter;
use Siiis\Support\ModuloDocumentos;
use Siiis\Support\ObjectIdValidator;

/**
 * Contenido sobre la colección unificada `Documentos` (spec 10.9; DEC-B30 a DEC-B41, PROPUESTA):
 * - Crea cualquier integrante con sesión; lo nuevo queda `en_revision` y oculto (DEC-B32).
 * - Los revisores (`Roles::REVISORES_CONTENIDO`) publican, editan y borran cualquier documento (DEC-B33).
 * - El autor edita y borra lo suyo solo mientras sigue `en_revision` (DEC-B34, DEC-B35).
 * - Cada actor ve lo público más lo suyo; los revisores, todo (DEC-B36).
 */
class DocumentosService
{
    public function __construct(
        private readonly DocumentosRepository $documentos,
        private readonly UsuariosRepository $usuarios,
        private readonly Clock $clock,
    ) {
    }

    /**
     * GET /projects y GET /articles. `$actorId`/`$actorRol` son null para visitantes anónimos.
     *
     * @param array{page: int, per_page: int, categoria: string|null, estado: string|null, visible: bool|null,
     *              autor: ObjectId|null, integrante: ObjectId|null} $query
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public function listar(ModuloDocumentos $modulo, array $query, ?string $actorId, ?string $actorRol): array
    {
        $actor = ObjectIdValidator::toObjectId($actorId);
        $alcance = match (true) {
            Roles::esRevisorContenido($actorRol) => DocumentoCriterios::ALCANCE_TODO,
            $actor !== null => DocumentoCriterios::ALCANCE_PROPIO,
            default => DocumentoCriterios::ALCANCE_PUBLICO,
        };

        ['items' => $items, 'total' => $total] = $this->documentos->paginar(
            new DocumentoCriterios(
                categorias: $query['categoria'] !== null ? [$query['categoria']] : $modulo->categorias(),
                alcance: $alcance,
                actor: $actor,
                estado: $query['estado'],
                visible: $query['visible'],
                autor: $query['autor'],
                integrante: $query['integrante'],
            ),
            $query['page'],
            $query['per_page'],
        );

        $usuarios = $this->usuarios->findResumenesByIds(DocumentoPresenter::usuarioIds($items));

        return [
            'data' => array_map(static fn (array $d): array => DocumentoPresenter::resumen($d, $usuarios), $items),
            'meta' => [
                'page' => $query['page'],
                'per_page' => $query['per_page'],
                'total' => $total,
                'total_pages' => (int) ceil($total / $query['per_page']),
            ],
        ];
    }

    /**
     * GET /{modulo}/{id}.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND si no existe, es de otro módulo o el actor no puede verlo
     */
    public function obtener(ModuloDocumentos $modulo, string $id, ?string $actorId, ?string $actorRol): array
    {
        return $this->detalle($this->visiblePara($modulo, $id, $actorId, $actorRol), $actorId, $actorRol);
    }

    /**
     * POST /{modulo} (DEC-B32, DEC-B33): el actor queda como autor. Solo un revisor puede crear el documento
     * ya publicado o visible.
     *
     * @param array{campos: array<string, mixed>, categoria: string, estado: string|null, visible: bool|null} $input
     *
     * @return array<string, mixed> detalle del documento creado
     *
     * @throws ApiException 400 VALIDATION_ERROR (integrantes), 403 FORBIDDEN
     */
    public function crear(ModuloDocumentos $modulo, array $input, string $actorId, string $actorRol): array
    {
        $autor = ObjectIdValidator::toObjectId($actorId) ?? throw ApiException::unauthorized();
        $estado = $input['estado'] ?? ModuloDocumentos::ESTADO_EN_REVISION;
        $visible = $input['visible'] ?? false;
        if (!Roles::esRevisorContenido($actorRol) && ($estado !== ModuloDocumentos::ESTADO_EN_REVISION || $visible)) {
            throw self::soloRevisores();
        }

        /** @var list<ObjectId> $integrantes */
        $integrantes = $input['campos']['integrantes'] ?? [];
        $this->validarIntegrantes($integrantes, []);

        $ahora = new UTCDateTime($this->clock->now());
        $documento = [
            '_id' => new ObjectId(),
            'categoria' => $input['categoria'],
            'titulo' => $input['campos']['titulo'] ?? '',
            'descripcion' => $input['campos']['descripcion'] ?? '',
            'contenido' => $input['campos']['contenido'] ?? null,
            'autor_id' => $autor,
            'integrantes' => $integrantes,
            'archivos_adjuntos' => [],
            'estado' => $estado,
            'visible' => $visible,
            'fecha_creacion' => $ahora,
            'fecha_actualizacion' => $ahora,
        ];
        $this->documentos->insertar($documento);

        return $this->detalle($documento, $actorId, $actorRol);
    }

    /**
     * PATCH /{modulo}/{id} (DEC-B33, DEC-B34): edición parcial. Reenviar `estado` o `visible` con su valor
     * actual no es un cambio (el formulario puede reenviar el objeto completo).
     *
     * @param array{campos: array<string, mixed>, categoria: string|null, estado: string|null, visible: bool|null} $input
     *
     * @return array<string, mixed> detalle actualizado
     *
     * @throws ApiException 400 VALIDATION_ERROR, 403 FORBIDDEN, 404 NOT_FOUND
     */
    public function actualizar(ModuloDocumentos $modulo, string $id, array $input, string $actorId, string $actorRol): array
    {
        $documento = $this->visiblePara($modulo, $id, $actorId, $actorRol);
        $esRevisor = Roles::esRevisorContenido($actorRol);
        $this->exigirEdicion($documento, $actorId, $esRevisor, 'editar');

        $estadoActual = (string) ($documento['estado'] ?? '');
        $nuevoEstado = $input['estado'] !== null && $input['estado'] !== $estadoActual ? $input['estado'] : null;
        $nuevoVisible = $input['visible'] !== null && $input['visible'] !== (($documento['visible'] ?? null) === true)
            ? $input['visible']
            : null;
        if (!$esRevisor && ($nuevoEstado !== null || $nuevoVisible !== null)) {
            throw self::soloRevisores();
        }

        $categoria = $input['categoria'] ?? (string) ($documento['categoria'] ?? '');
        $estadoFinal = $nuevoEstado ?? $estadoActual;
        if (!in_array($estadoFinal, ModuloDocumentos::estadosDe($categoria), true)) {
            throw ApiException::validation(['estado' => sprintf(
                'El estado %s no es válido para la categoría %s. Valores permitidos: %s.',
                $estadoFinal,
                $categoria,
                implode(', ', ModuloDocumentos::estadosDe($categoria)),
            )]);
        }

        $set = $input['campos'];
        if (array_key_exists('integrantes', $set)) {
            /** @var list<ObjectId> $integrantes */
            $integrantes = $set['integrantes'];
            $this->validarIntegrantes($integrantes, DocumentoPresenter::integranteIds($documento));
        }
        if ($input['categoria'] !== null) {
            $set['categoria'] = $input['categoria'];
        }
        if ($nuevoEstado !== null) {
            $set['estado'] = $nuevoEstado;
        }
        if ($nuevoVisible !== null) {
            $set['visible'] = $nuevoVisible;
        }
        $set['fecha_actualizacion'] = new UTCDateTime($this->clock->now());

        /** @var ObjectId $objectId */
        $objectId = $documento['_id'];
        // El autor solo escribe si el documento sigue en revisión (otro revisor pudo publicarlo entretanto).
        if (!$this->documentos->actualizar($objectId, $set, $esRevisor ? null : ModuloDocumentos::ESTADO_EN_REVISION)) {
            throw $esRevisor ? self::noEncontrado($modulo) : self::yaNoEnRevision('editar');
        }

        return $this->obtener($modulo, (string) $objectId, $actorId, $actorRol);
    }

    /**
     * DELETE /{modulo}/{id} (DEC-B35): borrado físico.
     *
     * @throws ApiException 403 FORBIDDEN, 404 NOT_FOUND
     */
    public function eliminar(ModuloDocumentos $modulo, string $id, string $actorId, string $actorRol): void
    {
        $documento = $this->visiblePara($modulo, $id, $actorId, $actorRol);
        $esRevisor = Roles::esRevisorContenido($actorRol);
        $this->exigirEdicion($documento, $actorId, $esRevisor, 'borrar');

        /** @var ObjectId $objectId */
        $objectId = $documento['_id'];
        if (!$this->documentos->eliminar($objectId, $esRevisor ? null : ModuloDocumentos::ESTADO_EN_REVISION)) {
            throw $esRevisor ? self::noEncontrado($modulo) : self::yaNoEnRevision('borrar');
        }
    }

    /**
     * Qué puede hacer el actor con el documento (lo usa el frontend para mostrar botones).
     *
     * @param array<string, mixed> $documento
     *
     * @return array{editar: bool, borrar: bool, publicar: bool}
     */
    public static function permisos(array $documento, ?string $actorId, ?string $actorRol): array
    {
        $esRevisor = Roles::esRevisorContenido($actorRol);
        $esAutorEnRevision = self::esAutor($documento, $actorId)
            && ($documento['estado'] ?? null) === ModuloDocumentos::ESTADO_EN_REVISION;

        return [
            'editar' => $esRevisor || $esAutorEnRevision,
            'borrar' => $esRevisor || $esAutorEnRevision,
            'publicar' => $esRevisor,
        ];
    }

    /**
     * @param array<string, mixed> $documento
     */
    public static function esPublico(array $documento): bool
    {
        return ($documento['visible'] ?? null) === true
            && ($documento['estado'] ?? null) !== ModuloDocumentos::ESTADO_EN_REVISION;
    }

    /**
     * Documento del módulo que el actor puede ver (DEC-B36). Para quien no puede verlo, "no existe": no se
     * revela si hay un borrador con ese id.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND
     */
    private function visiblePara(ModuloDocumentos $modulo, string $id, ?string $actorId, ?string $actorRol): array
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        $documento = $objectId === null ? null : $this->documentos->findById($objectId);
        if ($documento === null || !$modulo->tieneCategoria((string) ($documento['categoria'] ?? ''))) {
            throw self::noEncontrado($modulo);
        }

        $puedeVer = Roles::esRevisorContenido($actorRol)
            || self::esPublico($documento)
            || self::esAutor($documento, $actorId)
            || self::esIntegrante($documento, $actorId);
        if (!$puedeVer) {
            throw self::noEncontrado($modulo);
        }

        return $documento;
    }

    /**
     * @param array<string, mixed> $documento
     *
     * @throws ApiException 403 FORBIDDEN
     */
    private function exigirEdicion(array $documento, string $actorId, bool $esRevisor, string $accion): void
    {
        if ($esRevisor) {
            return;
        }
        if (!self::esAutor($documento, $actorId)) {
            throw ApiException::forbidden(sprintf('Solo el autor o un revisor puede %s este documento.', $accion));
        }
        if (($documento['estado'] ?? null) !== ModuloDocumentos::ESTADO_EN_REVISION) {
            throw self::yaNoEnRevision($accion);
        }
    }

    /**
     * Los integrantes deben existir (spec 8.5). Los nuevos deben estar activos; los que ya estaban pueden
     * quedarse aunque se hayan desactivado (DEC-B38).
     *
     * @param list<ObjectId> $nuevos
     * @param list<ObjectId> $actuales
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    private function validarIntegrantes(array $nuevos, array $actuales): void
    {
        if ($nuevos === []) {
            return;
        }

        $actualesPorId = array_flip(array_map('strval', $actuales));
        $usuarios = $this->usuarios->findResumenesByIds($nuevos);
        $invalidos = [];
        foreach ($nuevos as $id) {
            $usuario = $usuarios[(string) $id] ?? null;
            $yaEstaba = isset($actualesPorId[(string) $id]);
            if ($usuario === null || (!$yaEstaba && ($usuario['estado'] ?? null) !== true)) {
                $invalidos[] = (string) $id;
            }
        }

        if ($invalidos !== []) {
            throw ApiException::validation([
                'integrantes' => 'Estos usuarios no existen o están inactivos: ' . implode(', ', $invalidos) . '.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $documento
     *
     * @return array<string, mixed>
     */
    private function detalle(array $documento, ?string $actorId, ?string $actorRol): array
    {
        $usuarios = $this->usuarios->findResumenesByIds(DocumentoPresenter::usuarioIds([$documento]));

        return DocumentoPresenter::detalle($documento, $usuarios, self::permisos($documento, $actorId, $actorRol));
    }

    /**
     * @param array<string, mixed> $documento
     */
    private static function esAutor(array $documento, ?string $actorId): bool
    {
        $autor = $documento['autor_id'] ?? null;

        return $actorId !== null && $autor instanceof ObjectId && (string) $autor === $actorId;
    }

    /**
     * @param array<string, mixed> $documento
     */
    private static function esIntegrante(array $documento, ?string $actorId): bool
    {
        if ($actorId === null) {
            return false;
        }
        foreach (DocumentoPresenter::integranteIds($documento) as $id) {
            if ((string) $id === $actorId) {
                return true;
            }
        }

        return false;
    }

    private static function noEncontrado(ModuloDocumentos $modulo): ApiException
    {
        return new ApiException(404, 'NOT_FOUND', sprintf('El %s no existe.', $modulo->nombre()));
    }

    private static function soloRevisores(): ApiException
    {
        return ApiException::forbidden(
            'Solo un superadministrador, administrador o docente director puede publicar o cambiar la visibilidad.',
        );
    }

    private static function yaNoEnRevision(string $accion): ApiException
    {
        return ApiException::forbidden(sprintf(
            'Solo puedes %s tus documentos mientras están en revisión; pide el cambio a un revisor.',
            $accion,
        ));
    }
}
