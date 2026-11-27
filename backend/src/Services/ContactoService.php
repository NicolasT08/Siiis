<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\CorreoInstitucional;
use Siiis\Support\Logger;
use Siiis\Support\MensajeContactoPresenter;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\Paginacion;
use Throwable;

/**
 * Formulario de contacto (RF-03; DEC-B46 y DEC-B47, PROPUESTA): cada mensaje se guarda en
 * `Mensajes_Contacto` y se reenvía con una plantilla fija al buzón del semillero, con "Responder a" hacia el
 * remitente. No hay respuesta automática al remitente.
 */
class ContactoService
{
    public function __construct(
        private readonly MensajesContactoRepository $mensajes,
        private readonly MailService $mail,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /contact. Si el correo falla, el mensaje ya quedó guardado y solo se registra el error (DEC-B47).
     *
     * @param array{nombre: string, correo: string, asunto: string, mensaje: string} $input
     */
    public function enviar(array $input): void
    {
        $id = new ObjectId();
        $this->mensajes->insertar([
            '_id' => $id,
            'nombre' => $input['nombre'],
            'correo' => $input['correo'],
            'asunto' => $input['asunto'],
            'mensaje' => $input['mensaje'],
            'estado' => MensajesContactoRepository::ESTADO_NO_LEIDO,
            'fecha' => new UTCDateTime($this->clock->now()),
        ]);

        try {
            $correo = self::correo($input, $this->settings->mailLogoUrl);
            $this->mail->send(
                self::destino($this->settings),
                'Mensaje de contacto: ' . $input['asunto'] . ' - Semillero SIIIS',
                $correo['html'],
                $correo['text'],
                [$input['correo'], $input['nombre']],
            );
        } catch (Throwable $e) {
            $this->logger->error('No se pudo reenviar el mensaje de contacto', [
                'mensaje_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /contact/messages (buzón de administradores).
     *
     * @param array{page: int, per_page: int, estado: string|null} $query
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int,
     *               total_pages: int}}
     */
    public function listar(array $query): array
    {
        ['items' => $items, 'total' => $total] = $this->mensajes->paginar(
            $query['estado'],
            $query['page'],
            $query['per_page'],
        );

        return [
            'data' => array_map(static fn (array $m): array => MensajeContactoPresenter::presentar($m), $items),
            'meta' => Paginacion::meta($query['page'], $query['per_page'], $total),
        ];
    }

    /**
     * PATCH /contact/messages/{id}.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function cambiarEstado(string $id, string $estado): array
    {
        $oid = ObjectIdValidator::toObjectId($id) ?? throw self::noEncontrado();
        if (!$this->mensajes->cambiarEstado($oid, $estado)) {
            throw self::noEncontrado();
        }

        return MensajeContactoPresenter::presentar($this->mensajes->findById($oid) ?? throw self::noEncontrado());
    }

    /**
     * Buzón que recibe los mensajes: `CONTACTO_CORREO_DESTINO`, o el remitente configurado del SMTP.
     */
    public static function destino(Settings $s): string
    {
        foreach ([$s->contactoCorreoDestino, $s->mailFromAddress, $s->mailUsername] as $correo) {
            if ($correo !== '') {
                return $correo;
            }
        }

        return '';
    }

    /**
     * Plantilla institucional (DEC-B68). Este correo sí se puede responder: la respuesta le llega al remitente.
     *
     * @param array{nombre: string, correo: string, asunto: string, mensaje: string} $input
     *
     * @return array{html: string, text: string}
     */
    private static function correo(array $input, string $logoUrl): array
    {
        return CorreoInstitucional::componer(
            $logoUrl,
            'Estimado equipo del Semillero SIIIS:',
            ['Se recibió un nuevo mensaje a través del formulario de contacto del sitio web.'],
            detalles: [
                ['Nombre', $input['nombre']],
                ['Correo', $input['correo']],
                ['Asunto', $input['asunto']],
            ],
            mensaje: $input['mensaje'],
            respuesta: 'Para contestar, responda este correo: la respuesta le llegará directamente a '
                . $input['nombre'] . '.',
        );
    }

    private static function noEncontrado(): ApiException
    {
        return ApiException::notFound('El mensaje no existe.');
    }
}
