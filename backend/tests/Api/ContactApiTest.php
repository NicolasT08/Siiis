<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Services\MailService;
use Siiis\Support\Logger;
use Siiis\Tests\Support\FakeMailService;
use Slim\App;

/**
 * Contacto (DEC-B46 a DEC-B48): POST /contact y el buzón GET/PATCH /contact/messages.
 */
final class ContactApiTest extends AuthTestCase
{
    private const DESTINO = 'semillero@uptc.edu.co';

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    private FakeMailService $mail;

    private int $segundos = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new FakeMailService();
        $this->extraOverrides = [MailService::class => $this->mail];
        $this->app = $this->authApp(['CONTACTO_CORREO_DESTINO' => self::DESTINO]);
    }

    public function testGuardaElMensajeYLoReenviaAlBuzon(): void
    {
        $response = $this->send('POST', '/api/v1/contact', [
            'nombre' => "  Carlos\r\nBcc: victima@example.com ",
            'correo' => ' Carlos@Example.COM ',
            'asunto' => "Información\n\nsobre el semillero",
            'mensaje' => "Hola,\n<b>quiero</b> unirme.",
            'estado' => 'leido',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['mensaje'], array_keys($this->json($response)));

        self::assertCount(1, $this->db->mensajes);
        $guardado = array_values($this->db->mensajes)[0];
        self::assertSame(['_id', 'nombre', 'correo', 'asunto', 'mensaje', 'estado', 'fecha'], array_keys($guardado));
        self::assertSame('Carlos Bcc: victima@example.com', $guardado['nombre']);
        self::assertSame('carlos@example.com', $guardado['correo']);
        self::assertSame('Información sobre el semillero', $guardado['asunto']);
        self::assertSame("Hola,\n<b>quiero</b> unirme.", $guardado['mensaje']);
        self::assertSame('no_leido', $guardado['estado']);
        self::assertInstanceOf(UTCDateTime::class, $guardado['fecha']);

        // Un solo correo, al buzón del semillero, con "Responder a" al remitente (sin respuesta automática).
        self::assertCount(1, $this->mail->enviados);
        $correo = $this->mail->enviados[0];
        self::assertSame(self::DESTINO, $correo['to']);
        self::assertSame(['carlos@example.com', 'Carlos Bcc: victima@example.com'], $correo['reply_to']);
        self::assertSame('Mensaje de contacto: Información sobre el semillero - Semillero SIIIS', $correo['subject']);
        self::assertStringContainsString('&lt;b&gt;quiero&lt;/b&gt;', $correo['html']);
        self::assertStringNotContainsString('<b>quiero</b>', $correo['html']);
        self::assertStringContainsString('<b>quiero</b> unirme.', $correo['text']);
    }

    public function testDestinoPorDefectoEsElRemitenteDelSmtp(): void
    {
        $app = $this->authApp(['MAIL_FROM_ADDRESS' => 'from@uptc.edu.co', 'MAIL_USERNAME' => 'user@gmail.com']);

        $app->handle($this->request('POST', '/api/v1/contact', $this->datos()));

        self::assertSame('from@uptc.edu.co', $this->mail->enviados[0]['to']);
    }

    public function testSiElCorreoFallaIgualResponde201YRegistraElError(): void
    {
        $logs = $this->storage . '/logs';
        $this->extraOverrides = [
            MailService::class => new FakeMailService(true),
            Logger::class => new Logger($logs),
        ];
        $app = $this->authApp(['CONTACTO_CORREO_DESTINO' => self::DESTINO]);

        $response = $app->handle($this->request('POST', '/api/v1/contact', $this->datos()));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->db->mensajes);
        $id = array_key_first($this->db->mensajes);
        $log = implode('', array_map('file_get_contents', glob($logs . '/*') ?: []));
        self::assertStringContainsString('No se pudo reenviar el mensaje de contacto', $log);
        self::assertStringContainsString((string) $id, $log);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function cuerposInvalidos(): iterable
    {
        yield 'vacío' => [(object) [], ['nombre', 'correo', 'asunto', 'mensaje']];
        yield 'correo inválido' => [['correo' => 'no-es-correo'] + self::base(), ['correo']];
        yield 'nombre largo' => [['nombre' => str_repeat('a', 81)] + self::base(), ['nombre']];
        yield 'asunto largo' => [['asunto' => str_repeat('a', 151)] + self::base(), ['asunto']];
        yield 'mensaje largo' => [['mensaje' => str_repeat('ñ', 3001)] + self::base(), ['mensaje']];
        yield 'solo espacios' => [['mensaje' => " \n\t "] + self::base(), ['mensaje']];
        yield 'inyección en correo' => [['correo' => ['$ne' => '']] + self::base(), ['correo']];
        yield 'inyección en mensaje' => [['mensaje' => ['$gt' => '']] + self::base(), ['mensaje']];
        yield 'número en asunto' => [['asunto' => 12345] + self::base(), []];
    }

    /**
     * @param list<string> $campos
     */
    #[DataProvider('cuerposInvalidos')]
    public function testValidacion(mixed $body, array $campos): void
    {
        $response = $this->send('POST', '/api/v1/contact', $body);

        if ($campos === []) {
            // Un número se acepta como texto, igual que en el resto de formularios.
            self::assertSame(201, $response->getStatusCode());

            return;
        }
        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($campos, array_keys($this->json($response)['error']['fields']));
        self::assertSame([], $this->db->mensajes);
        self::assertSame([], $this->mail->enviados);
    }

    public function testRateLimitPorIp(): void
    {
        $app = $this->authApp(['RATE_LIMIT_CONTACT_MAX' => '2', 'CONTACTO_CORREO_DESTINO' => self::DESTINO]);

        self::assertSame(201, $app->handle($this->request('POST', '/api/v1/contact', $this->datos()))->getStatusCode());
        self::assertSame(201, $app->handle($this->request('POST', '/api/v1/contact', $this->datos()))->getStatusCode());
        $bloqueado = $app->handle($this->request('POST', '/api/v1/contact', $this->datos()));

        $this->assertError($bloqueado, 429, 'RATE_LIMITED');
        self::assertNotSame('', $bloqueado->getHeaderLine('Retry-After'));
        self::assertCount(2, $this->db->mensajes);
        self::assertCount(2, $this->mail->enviados);
    }

    public function testLimitesPorDefectoDeLasRutasPublicas(): void
    {
        $s = $this->settings();

        self::assertSame(20, $s->rateLimitContactMax);
        self::assertSame(3600, $s->rateLimitContactWindowSeconds);
        self::assertSame(10, $s->rateLimitReviewsMax);
        self::assertSame(3600, $s->rateLimitReviewsWindowSeconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function administrativos(): iterable
    {
        yield 'superadministrador' => ['superadministrador'];
        yield 'administrador' => ['administrador'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noAdministrativos(): iterable
    {
        yield 'docente_director' => ['docente_director'];
        yield 'estudiante_lider' => ['estudiante_lider'];
        yield 'estudiante' => ['estudiante'];
    }

    #[DataProvider('administrativos')]
    public function testBuzonListaYFiltra(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $leido = $this->mensaje(['estado' => 'leido']);
        $noLeido = $this->mensaje(['estado' => 'no_leido']);

        $response = $this->send('GET', '/api/v1/contact/messages', null, $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame([(string) $noLeido, (string) $leido], array_column($body['data'], 'id'));
        self::assertSame(['id', 'nombre', 'correo', 'asunto', 'mensaje', 'estado', 'fecha'], array_keys($body['data'][0]));
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 2, 'total_pages' => 1], $body['meta']);

        $filtrado = $this->json($this->send('GET', '/api/v1/contact/messages?estado=leido&per_page=1', null, $token));
        self::assertSame([(string) $leido], array_column($filtrado['data'], 'id'));
    }

    public function testBuzonQueryInvalida(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        foreach (['estado=archivado', 'estado[$ne]=leido', 'per_page=51', 'page=0'] as $query) {
            $this->assertError($this->send('GET', '/api/v1/contact/messages?' . $query, null, $token), 400, 'VALIDATION_ERROR');
        }
    }

    #[DataProvider('administrativos')]
    public function testMarcarLeidoYNoLeido(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->mensaje(['estado' => 'no_leido']);

        $response = $this->send('PATCH', '/api/v1/contact/messages/' . $id, ['estado' => 'leido', 'mensaje' => 'otro'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $mensaje = $this->json($response)['mensaje_contacto'];
        self::assertSame('leido', $mensaje['estado']);
        self::assertSame('Mensaje de prueba', $mensaje['mensaje']);
        self::assertSame('leido', $this->db->mensajes[(string) $id]['estado']);

        $this->send('PATCH', '/api/v1/contact/messages/' . $id, ['estado' => 'no_leido'], $token);
        self::assertSame('no_leido', $this->db->mensajes[(string) $id]['estado']);
    }

    public function testMarcarConEstadoInvalidoOIdInexistente(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->mensaje(['estado' => 'no_leido']);

        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/' . $id, ['estado' => 'archivado'], $token), 400, 'VALIDATION_ERROR');
        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/' . $id, ['estado' => ['$ne' => 'x']], $token), 400, 'VALIDATION_ERROR');
        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/' . new ObjectId(), ['estado' => 'leido'], $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/xyz', ['estado' => 'leido'], $token), 404, 'NOT_FOUND');
        self::assertSame('no_leido', $this->db->mensajes[(string) $id]['estado']);
    }

    #[DataProvider('noAdministrativos')]
    public function testBuzonSoloAdministrativos(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->mensaje([]);

        $this->assertError($this->send('GET', '/api/v1/contact/messages', null, $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/' . $id, ['estado' => 'leido'], $token), 403, 'FORBIDDEN');
    }

    public function testBuzonSinSesion(): void
    {
        $this->assertError($this->send('GET', '/api/v1/contact/messages'), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('PATCH', '/api/v1/contact/messages/' . new ObjectId(), ['estado' => 'leido']), 401, 'UNAUTHORIZED');
    }

    /**
     * @return array<string, string>
     */
    private static function base(): array
    {
        return [
            'nombre' => 'Carlos',
            'correo' => 'carlos@example.com',
            'asunto' => 'Información',
            'mensaje' => 'Hola, quiero información.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function datos(): array
    {
        return self::base();
    }

    private function send(string $method, string $path, mixed $body = null, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request($method, $path, $body, $token === null ? [] : self::bearer($token)));
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function mensaje(array $campos): ObjectId
    {
        $id = new ObjectId();
        $this->db->mensajes[(string) $id] = $campos + [
            '_id' => $id,
            'nombre' => 'Visitante',
            'correo' => 'visitante@example.com',
            'asunto' => 'Asunto',
            'mensaje' => 'Mensaje de prueba',
            'estado' => 'no_leido',
            'fecha' => new UTCDateTime(($this->clock->now()->getTimestamp() + ++$this->segundos) * 1000),
        ];

        return $id;
    }
}
