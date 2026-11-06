<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
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
            $this->mail->send(
                self::destino($this->settings),
                'Contacto desde el sitio web: ' . $input['asunto'],
                self::html($input),
                self::texto($input),
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
     * @param array{nombre: string, correo: string, asunto: string, mensaje: string} $input
     */
    private static function html(array $input): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $mensaje = nl2br($e($input['mensaje']));

        return <<<HTML
            <p>Se recibió un mensaje desde el formulario de contacto del sitio del Semillero SIIIS.</p>
            <p><strong>Nombre:</strong> {$e($input['nombre'])}<br>
            <strong>Correo:</strong> {$e($input['correo'])}<br>
            <strong>Asunto:</strong> {$e($input['asunto'])}</p>
            <p>{$mensaje}</p>
            <p>Para contestar, responde a este correo: la respuesta le llega al remitente.</p>
            HTML;
    }

    /**
     * @param array{nombre: string, correo: string, asunto: string, mensaje: string} $input
     */
    private static function texto(array $input): string
    {
        return "Mensaje desde el formulario de contacto del Semillero SIIIS.\n\n"
            . "Nombre: {$input['nombre']}\nCorreo: {$input['correo']}\nAsunto: {$input['asunto']}\n\n"
            . $input['mensaje'];
    }

    private static function noEncontrado(): ApiException
    {
        return ApiException::notFound('El mensaje no existe.');
    }
}
