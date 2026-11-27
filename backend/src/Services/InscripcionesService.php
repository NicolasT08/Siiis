<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\InscripcionesRepository;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\CloudinaryUrl;
use Siiis\Support\InscripcionPresenter;
use Siiis\Support\Logger;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\Paginacion;
use Siiis\Support\Serializer;
use Siiis\Validation\FileValidator;
use Throwable;

/**
 * Inscripción al semillero (RF-12, RF-13; DEC-B49 a DEC-B52, PROPUESTA):
 * - Cualquiera envía una solicitud con 1 a 3 documentos, que van privados a Cloudinary (DEC-B50).
 * - Solo `superadministrador` y `administrador` la consultan y la resuelven (la ruta lleva RoleMiddleware).
 * - Solo se pasa de `pendiente` a `aceptada` o `rechazada`; aceptar no crea la cuenta (flujo manual, 8.2).
 * - Si un correo falla, la solicitud ya está guardada y solo se registra el error (DEC-B47).
 */
class InscripcionesService
{
    public function __construct(
        private readonly InscripcionesRepository $inscripciones,
        private readonly CloudinaryService $cloudinary,
        private readonly MailService $mail,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /inscriptions. Orden: duplicado → subidas (con compensación) → MongoDB → correos.
     *
     * @param array{nombre_completo: string, correo: string, telefono: string|null, programa_academico: string,
     *              semestre: int, carta_motivacion: string, codigo_estudiantil: string|null,
     *              areas_interes: list<string>}                                    $input
     * @param list<array{path: string, mime: string, size: int, nombre: string}> $archivos ya validados
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 409 INSCRIPTION_PENDING, 502 CLOUDINARY_UNAVAILABLE
     */
    public function crear(array $input, array $archivos): array
    {
        // Antes de subir: no se gastan subidas en un duplicado.
        if ($this->inscripciones->tienePendiente($input['correo'])) {
            throw new ApiException(
                409,
                'INSCRIPTION_PENDING',
                'Ya hay una solicitud de inscripción pendiente con este correo. Espera la respuesta del semillero.',
            );
        }

        $id = new ObjectId();
        $documentos = $this->subir($archivos, (string) $id);

        $inscripcion = [
            '_id' => $id,
            'nombre_completo' => $input['nombre_completo'],
            'correo' => $input['correo'],
            'telefono' => $input['telefono'],
            'programa_academico' => $input['programa_academico'],
            'semestre' => $input['semestre'],
            'carta_motivacion' => $input['carta_motivacion'],
            // Campos opcionales nuevos (DEC-B63, PROPUESTA pendiente del equipo de BD).
            'codigo_estudiantil' => $input['codigo_estudiantil'],
            'areas_interes' => $input['areas_interes'],
            'documentos_adjuntos' => $documentos,
            'estado_solicitud' => InscripcionesRepository::ESTADO_PENDIENTE,
            'fecha_solicitud' => new UTCDateTime($this->clock->now()),
        ];
        try {
            $this->inscripciones->insertar($inscripcion);
        } catch (Throwable $e) {
            $this->borrarDocumentos($documentos, (string) $id, 'compensación tras fallo en MongoDB');

            throw $e;
        }

        $this->correoRecibida($inscripcion);
        $this->correoAviso($inscripcion);

        return InscripcionPresenter::recibida($inscripcion);
    }

    /**
     * GET /inscriptions.
     *
     * @param array{page: int, per_page: int, estado: string|null} $query
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int,
     *               total_pages: int}}
     */
    public function listar(array $query): array
    {
        ['items' => $items, 'total' => $total] = $this->inscripciones->paginar(
            $query['estado'],
            $query['page'],
            $query['per_page'],
        );

        return [
            'data' => array_map(static fn (array $i): array => InscripcionPresenter::resumen($i), $items),
            'meta' => Paginacion::meta($query['page'], $query['per_page'], $total),
        ];
    }

    /**
     * GET /inscriptions/{id}: detalle con enlaces firmados que vencen en `INSCRIPCION_ENLACE_TTL_SECONDS`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND, 502 CLOUDINARY_UNAVAILABLE
     */
    public function obtener(string $id): array
    {
        $inscripcion = $this->buscar($id);

        return InscripcionPresenter::detalle($inscripcion, $this->enlaces($inscripcion));
    }

    /**
     * PATCH /inscriptions/{id}: `pendiente` → `aceptada` | `rechazada`, atómico (DEC-B52).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND, 409 INSCRIPTION_CLOSED
     */
    public function resolver(string $id, string $estado): array
    {
        $inscripcion = $this->buscar($id);
        /** @var ObjectId $oid */
        $oid = $inscripcion['_id'];
        if (!$this->inscripciones->resolver($oid, $estado)) {
            throw new ApiException(
                409,
                'INSCRIPTION_CLOSED',
                'La solicitud ya fue resuelta y no se puede cambiar.',
            );
        }

        $inscripcion['estado_solicitud'] = $estado;
        $this->correoResuelta($inscripcion);

        return InscripcionPresenter::detalle($inscripcion, $this->enlaces($inscripcion));
    }

    /**
     * @param list<array{path: string, mime: string, size: int, nombre: string}> $archivos
     *
     * @return list<array{url: string, public_id: string, nombre_archivo: string}>
     *
     * @throws ApiException 415 UNSUPPORTED_MEDIA_TYPE (archivo rechazado) o 502 CLOUDINARY_UNAVAILABLE
     */
    private function subir(array $archivos, string $inscripcionId): array
    {
        $documentos = [];
        foreach ($archivos as $archivo) {
            try {
                $subido = $this->cloudinary->uploadPrivado(
                    $archivo['path'],
                    $this->settings->cloudinaryInscripcionesFolder,
                    CloudinaryService::FORMATOS_ADJUNTO,
                    $archivo['mime'] === FileValidator::MIME_PDF
                        ? []
                        : CloudinaryService::transformacionImagenAdjunta($this->settings),
                );
            } catch (ArchivoRechazadoException $e) {
                // Un archivo inválido rechaza toda la solicitud (13a): se borra lo ya subido.
                $this->logger->warning('Cloudinary rechazó un documento de inscripción', [
                    'inscripcion_id' => $inscripcionId,
                    'error' => $e->getMessage(),
                ]);
                $this->borrarDocumentos($documentos, $inscripcionId, 'compensación tras archivo rechazado');

                throw new ApiException(
                    415,
                    'UNSUPPORTED_MEDIA_TYPE',
                    sprintf(
                        'El archivo "%s" está dañado o no es un PDF o una imagen válida. Revísalo y vuelve a enviar la solicitud.',
                        $archivo['nombre'],
                    ),
                );
            } catch (CloudinaryException $e) {
                $this->logger->error('No se pudo subir un documento de inscripción', [
                    'inscripcion_id' => $inscripcionId,
                    'error' => $e->getMessage(),
                ]);
                $this->borrarDocumentos($documentos, $inscripcionId, 'compensación tras fallo de subida');

                throw new ApiException(
                    502,
                    'CLOUDINARY_UNAVAILABLE',
                    'No se pudieron guardar los documentos. Intenta de nuevo más tarde.',
                );
            }
            $documentos[] = $subido + ['nombre_archivo' => $archivo['nombre']];
        }

        return $documentos;
    }

    /**
     * Compensación: borra de Cloudinary lo ya subido. Si falla, queda en el log para borrarlo a mano.
     *
     * @param list<array{url: string, public_id: string, nombre_archivo: string}> $documentos
     */
    private function borrarDocumentos(array $documentos, string $inscripcionId, string $motivo): void
    {
        foreach ($documentos as $documento) {
            try {
                $this->cloudinary->delete($documento['public_id'], true);
            } catch (Throwable $e) {
                $this->logger->error('Documento de inscripción huérfano en Cloudinary', [
                    'inscripcion_id' => $inscripcionId,
                    'public_id' => $documento['public_id'],
                    'motivo' => $motivo,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $inscripcion
     *
     * @return list<array{nombre_archivo: string, formato: string, url: string, expira: string}>
     *
     * @throws ApiException 502 CLOUDINARY_UNAVAILABLE
     */
    private function enlaces(array $inscripcion): array
    {
        $expira = $this->clock->now()->getTimestamp() + $this->settings->inscripcionEnlaceTtlSeconds;
        $expiraIso = Serializer::formatDate((new \DateTimeImmutable())->setTimestamp($expira));
        $documentos = is_array($inscripcion['documentos_adjuntos'] ?? null) ? $inscripcion['documentos_adjuntos'] : [];

        $enlaces = [];
        foreach ($documentos as $documento) {
            if (!is_array($documento) || !is_string($documento['public_id'] ?? null) || !is_string($documento['url'] ?? null)) {
                continue;
            }
            $formato = CloudinaryUrl::formato($documento['url']);
            try {
                $url = $this->cloudinary->enlacePrivado($documento['public_id'], $formato, $expira);
            } catch (CloudinaryException $e) {
                $this->logger->error('No se pudo firmar el enlace de un documento de inscripción', [
                    'error' => $e->getMessage(),
                ]);

                throw new ApiException(
                    502,
                    'CLOUDINARY_UNAVAILABLE',
                    'No se pudieron preparar los enlaces de los documentos. Intenta de nuevo más tarde.',
                );
            }
            $enlaces[] = [
                'nombre_archivo' => is_string($documento['nombre_archivo'] ?? null) ? $documento['nombre_archivo'] : '',
                'formato' => $formato,
                'url' => $url,
                'expira' => $expiraIso,
            ];
        }

        return $enlaces;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND
     */
    private function buscar(string $id): array
    {
        $oid = ObjectIdValidator::toObjectId($id) ?? throw self::noEncontrada();

        return $this->inscripciones->findById($oid) ?? throw self::noEncontrada();
    }

    /**
     * @param array<string, mixed> $inscripcion
     */
    private function correoRecibida(array $inscripcion): void
    {
        $nombre = self::texto($inscripcion, 'nombre_completo');
        $this->enviar(
            self::texto($inscripcion, 'correo'),
            'Recibimos tu solicitud de inscripción — Semillero SIIIS',
            '<p>Hola, ' . self::e($nombre) . ':</p>'
                . '<p>Recibimos tu solicitud de inscripción al Semillero SIIIS. El equipo la revisará y te '
                . 'escribirá a este correo con la respuesta.</p>',
            "Hola, {$nombre}:\n\nRecibimos tu solicitud de inscripción al Semillero SIIIS. El equipo la revisará y "
                . 'te escribirá a este correo con la respuesta.',
            $inscripcion,
        );
    }

    /**
     * @param array<string, mixed> $inscripcion
     */
    private function correoAviso(array $inscripcion): void
    {
        $destino = $this->settings->inscripcionCorreoAviso !== ''
            ? $this->settings->inscripcionCorreoAviso
            : ContactoService::destino($this->settings);
        $nombre = self::texto($inscripcion, 'nombre_completo');
        $programa = self::texto($inscripcion, 'programa_academico');
        $semestre = is_int($inscripcion['semestre'] ?? null) ? $inscripcion['semestre'] : 0;

        $this->enviar(
            $destino,
            'Nueva solicitud de inscripción: ' . $nombre,
            '<p>Llegó una nueva solicitud de inscripción al Semillero SIIIS.</p>'
                . '<p><strong>Nombre:</strong> ' . self::e($nombre) . '<br>'
                . '<strong>Correo:</strong> ' . self::e(self::texto($inscripcion, 'correo')) . '<br>'
                . '<strong>Programa:</strong> ' . self::e($programa) . ' (semestre ' . $semestre . ')</p>'
                . '<p>Revísala en el panel de administración del sitio.</p>',
            "Nueva solicitud de inscripción al Semillero SIIIS.\n\nNombre: {$nombre}\nCorreo: "
                . self::texto($inscripcion, 'correo') . "\nPrograma: {$programa} (semestre {$semestre})\n\n"
                . 'Revísala en el panel de administración del sitio.',
            $inscripcion,
        );
    }

    /**
     * @param array<string, mixed> $inscripcion
     */
    private function correoResuelta(array $inscripcion): void
    {
        $nombre = self::texto($inscripcion, 'nombre_completo');
        $aceptada = ($inscripcion['estado_solicitud'] ?? null) === InscripcionesRepository::ESTADO_ACEPTADA;
        $cuerpo = $aceptada
            ? 'Tu solicitud de inscripción al Semillero SIIIS fue aceptada. El equipo del semillero se pondrá en '
                . 'contacto contigo para los siguientes pasos.'
            : 'Revisamos tu solicitud de inscripción al Semillero SIIIS y en esta ocasión no fue aceptada. '
                . 'Gracias por tu interés.';

        $this->enviar(
            self::texto($inscripcion, 'correo'),
            $aceptada
                ? 'Tu solicitud de inscripción fue aceptada — Semillero SIIIS'
                : 'Respuesta a tu solicitud de inscripción — Semillero SIIIS',
            '<p>Hola, ' . self::e($nombre) . ':</p><p>' . self::e($cuerpo) . '</p>',
            "Hola, {$nombre}:\n\n{$cuerpo}",
            $inscripcion,
        );
    }

    /**
     * @param array<string, mixed> $inscripcion
     */
    private function enviar(string $to, string $subject, string $html, string $text, array $inscripcion): void
    {
        try {
            $this->mail->send($to, $subject, $html, $text);
        } catch (Throwable $e) {
            $this->logger->error('No se pudo enviar un correo de inscripción', [
                'inscripcion_id' => (string) ($inscripcion['_id'] ?? ''),
                'asunto' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $datos
     */
    private static function texto(array $datos, string $campo): string
    {
        return is_string($datos[$campo] ?? null) ? $datos[$campo] : '';
    }

    private static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function noEncontrada(): ApiException
    {
        return ApiException::notFound('La solicitud de inscripción no existe.');
    }
}
