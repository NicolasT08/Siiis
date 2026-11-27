<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Cloudinary\Cloudinary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Application;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Services\ContactoService;
use Siiis\Services\MailService;
use Siiis\Tests\Support\FakeMailService;
use Siiis\Tests\Support\TestImages;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Fase 4 de punta a punta contra Atlas (base de pruebas), con la app real: reseñas con moderación, contacto con
 * buzón e inscripción con documentos privados en Cloudinary real (`siiis_test/inscripciones`). Los correos se
 * simulan para no escribir a direcciones inventadas. Todo lleva el prefijo `$marca` y se borra al terminar.
 */
final class InteraccionFlowIntegrationTest extends IntegrationTestCase
{
    private const PASSWORD = 'Clave-Integracion-2026';

    private const FOLDER = 'siiis_test/inscripciones';

    private string $marca;

    /** @var list<ObjectId> */
    private array $usuarios = [];

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    private Settings $appSettings;

    private FakeMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = 'it-fase4-' . bin2hex(random_bytes(4));
        $this->appSettings = Settings::fromEnv(
            ['MONGO_DB_NAME' => $this->mongo()->databaseName(), 'CLOUDINARY_INSCRIPCIONES_FOLDER' => self::FOLDER, 'APP_ENV' => 'testing']
                + $_ENV + getenv() + ['JWT_SECRET' => str_repeat('x', 32)],
            $this->settings()->storagePath,
        );
        self::assertNotSame('siiis', $this->appSettings->mongoDbName);
        $this->mail = new FakeMailService();
        $this->app = Application::create($this->appSettings, [
            Mongo::class => $this->mongo(),
            MailService::class => $this->mail,
        ]);
    }

    protected function tearDown(): void
    {
        if (self::$mongo === null || !isset($this->marca)) {
            return;
        }
        $prefijo = new Regex('^' . preg_quote($this->marca, '/'));
        $this->mongo()->collection(Collections::RESENAS)->deleteMany(['nombre_visible' => $prefijo]);
        $this->mongo()->collection(Collections::MENSAJES_CONTACTO)->deleteMany(['nombre' => $prefijo]);
        $inscripciones = $this->mongo()->collection(Collections::INSCRIPCIONES);
        foreach ($inscripciones->find(['nombre_completo' => $prefijo]) as $inscripcion) {
            $documentos = is_array($inscripcion) ? ($inscripcion['documentos_adjuntos'] ?? []) : [];
            foreach (is_array($documentos) ? $documentos : [] as $documento) {
                if (is_array($documento) && is_string($documento['public_id'] ?? null)) {
                    self::cloudinary($this->appSettings)->uploadApi()->destroy($documento['public_id'], [
                        'resource_type' => 'image',
                        'type' => 'authenticated',
                        'invalidate' => true,
                    ]);
                }
            }
        }
        $inscripciones->deleteMany(['nombre_completo' => $prefijo]);
        if ($this->usuarios !== []) {
            $ids = ['$in' => $this->usuarios];
            $this->mongo()->collection(Collections::SESIONES)->deleteMany(['usuario_id' => $ids]);
            $this->mongo()->collection(Collections::USUARIOS)->deleteMany(['_id' => $ids]);
            $this->mongo()->collection(Collections::CREDENCIALES)->deleteMany(['usuario_id' => $ids]);
        }
        foreach ([[Collections::RESENAS, 'nombre_visible'], [Collections::MENSAJES_CONTACTO, 'nombre'], [Collections::INSCRIPCIONES, 'nombre_completo']] as [$coleccion, $campo]) {
            self::assertSame(0, $this->mongo()->collection($coleccion)->countDocuments([$campo => $prefijo]), $coleccion);
        }
        TestImages::limpiar();
    }

    public function testResenasConModeracion(): void
    {
        [$integranteId, $tokenIntegrante] = $this->cuenta('integrante', 'estudiante');
        [, $tokenModerador] = $this->cuenta('moderador', 'docente_director');
        $antes = self::json($this->call('GET', '/reviews'))['resumen'];

        // Visitante: queda oculta y sin usuario.
        $visitante = $this->call('POST', '/reviews', ['nombre_visible' => $this->marca . ' visitante', 'comentario' => 'Muy buen semillero.', 'calificacion' => 5]);
        self::assertSame(201, $visitante->getStatusCode(), (string) $visitante->getBody());
        $idVisitante = self::json($visitante)['resena']['id'];
        self::assertSame('oculta', self::json($visitante)['resena']['estado']);

        // Integrante: visible con el nombre de su perfil.
        $integrante = $this->call('POST', '/reviews', ['nombre_visible' => 'Suplantado', 'comentario' => 'Aprendí mucho.', 'calificacion' => 3], $tokenIntegrante);
        self::assertSame(201, $integrante->getStatusCode(), (string) $integrante->getBody());
        $resena = self::json($integrante)['resena'];
        self::assertSame([$integranteId, $this->marca . '-integrante IT', 'visible'], [$resena['usuario_id'], $resena['nombre_visible'], $resena['estado']]);

        // Guardado en Atlas con los campos exactos del modelo (spec 8.2).
        $guardada = $this->mongo()->collection(Collections::RESENAS)->findOne(['_id' => new ObjectId($resena['id'])]);
        self::assertIsArray($guardada);
        self::assertSame(['_id', 'usuario_id', 'nombre_visible', 'comentario', 'calificacion', 'estado', 'fecha'], array_keys($guardada));
        self::assertEquals(new ObjectId($integranteId), $guardada['usuario_id']);
        self::assertInstanceOf(UTCDateTime::class, $guardada['fecha']);
        $anonima = $this->mongo()->collection(Collections::RESENAS)->findOne(['_id' => new ObjectId($idVisitante)]);
        self::assertIsArray($anonima);
        self::assertNull($anonima['usuario_id']);

        // El público nunca ve una oculta, en ninguna página; el resumen solo suma la visible (3).
        $publicas = $this->todas('/reviews');
        self::assertContains($resena['id'], $publicas['ids']);
        self::assertNotContains($idVisitante, $publicas['ids']);
        self::assertSame(['visible'], $publicas['estados']);
        $despues = self::json($this->call('GET', '/reviews'))['resumen'];
        self::assertSame($antes['calificaciones'] + 1, $despues['calificaciones']);

        // Moderación: el integrante no puede; el moderador ve la oculta, la aprueba y luego la borra.
        $this->assertError($this->call('PATCH', "/reviews/$idVisitante", ['estado' => 'visible'], $tokenIntegrante), 403, 'FORBIDDEN');
        self::assertContains($idVisitante, $this->todas('/reviews?estado=oculta', $tokenModerador)['ids']);
        self::assertSame('visible', self::json($this->call('PATCH', "/reviews/$idVisitante", ['estado' => 'visible'], $tokenModerador))['resena']['estado']);
        self::assertContains($idVisitante, $this->todas('/reviews')['ids']);
        self::assertSame(204, $this->call('DELETE', "/reviews/$idVisitante", null, $tokenModerador)->getStatusCode());
        self::assertNull($this->mongo()->collection(Collections::RESENAS)->findOne(['_id' => new ObjectId($idVisitante)]));
        $this->assertError($this->call('DELETE', "/reviews/$idVisitante", null, $tokenModerador), 404, 'NOT_FOUND');
    }

    public function testContactoConBuzon(): void
    {
        [, $tokenAdmin] = $this->cuenta('admin', 'administrador');
        [, $tokenDocente] = $this->cuenta('docente', 'docente_director');

        $enviado = $this->call('POST', '/contact', [
            'nombre' => $this->marca . " Remitente\r\nBcc: x@example.test",
            'correo' => 'Remitente@Example.TEST',
            'asunto' => 'Información',
            'mensaje' => "Hola,\nquiero información.",
        ]);
        self::assertSame(201, $enviado->getStatusCode(), (string) $enviado->getBody());

        $guardado = $this->mongo()->collection(Collections::MENSAJES_CONTACTO)->findOne(['nombre' => new Regex('^' . preg_quote($this->marca, '/'))]);
        self::assertIsArray($guardado);
        self::assertSame(['_id', 'nombre', 'correo', 'asunto', 'mensaje', 'estado', 'fecha'], array_keys($guardado));
        self::assertSame($this->marca . ' Remitente Bcc: x@example.test', $guardado['nombre']);
        self::assertSame(['remitente@example.test', 'no_leido'], [$guardado['correo'], $guardado['estado']]);
        self::assertInstanceOf(UTCDateTime::class, $guardado['fecha']);
        $id = (string) $guardado['_id'];

        // Un solo correo, al buzón configurado, con "Responder a" al remitente.
        self::assertCount(1, $this->mail->enviados);
        self::assertSame(ContactoService::destino($this->appSettings), $this->mail->enviados[0]['to']);
        self::assertSame('remitente@example.test', $this->mail->enviados[0]['reply_to'][0] ?? null);

        // Buzón: solo administrativos; marcar como leído queda en Atlas.
        $this->assertError($this->call('GET', '/contact/messages', null, $tokenDocente), 403, 'FORBIDDEN');
        $this->assertError($this->call('GET', '/contact/messages'), 401, 'UNAUTHORIZED');
        self::assertContains($id, $this->todas('/contact/messages?estado=no_leido', $tokenAdmin)['ids']);
        $leido = $this->call('PATCH', "/contact/messages/$id", ['estado' => 'leido'], $tokenAdmin);
        self::assertSame('leido', self::json($leido)['mensaje_contacto']['estado']);
        self::assertSame('leido', $this->mongo()->collection(Collections::MENSAJES_CONTACTO)->findOne(['_id' => new ObjectId($id)])['estado'] ?? null);
        self::assertNotContains($id, $this->todas('/contact/messages?estado=no_leido', $tokenAdmin)['ids']);
    }

    public function testInscripcionDeAspiranteHastaLaAceptacion(): void
    {
        if ($this->appSettings->cloudinaryCloudName === '' || $this->appSettings->cloudinaryApiSecret === '') {
            self::markTestSkipped('CLOUDINARY_* no configuradas: se omite la inscripción con Cloudinary real.');
        }
        [, $tokenAdmin] = $this->cuenta('admin', 'administrador');
        [, $tokenEstudiante] = $this->cuenta('estudiante', 'estudiante');
        $correo = $this->marca . '@uptc.edu.co';
        $datos = [
            'nombre_completo' => $this->marca . ' Aspirante',
            'correo' => strtoupper($correo),
            'programa_academico' => 'Ingeniería de Sistemas',
            'semestre' => '5',
            'carta_motivacion' => str_repeat('Quiero investigar en el semillero. ', 4),
            'acepta_tratamiento_datos' => 'true',
            'disponibilidad' => 'true',
        ];

        // Aspirante: formulario con dos documentos.
        $enviada = $this->multipart($datos, [
            TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf', 'carta.pdf'),
            TestImages::subida(TestImages::pngDeDimensiones(2000, 40), UPLOAD_ERR_OK, 'image/png', 'notas.png'),
        ]);
        self::assertSame(201, $enviada->getStatusCode(), (string) $enviada->getBody());
        $id = self::json($enviada)['inscripcion']['id'];
        self::assertStringNotContainsString('cloudinary', (string) $enviada->getBody());
        self::assertSame([$correo, ContactoService::destino($this->appSettings)], array_column($this->mail->enviados, 'to'));

        // En Atlas: campos del modelo; URL guardada sin firma, que no abre.
        $guardada = $this->mongo()->collection(Collections::INSCRIPCIONES)->findOne(['_id' => new ObjectId($id)]);
        self::assertIsArray($guardada);
        self::assertSame(
            ['_id', 'nombre_completo', 'correo', 'telefono', 'programa_academico', 'semestre', 'carta_motivacion', 'codigo_estudiantil', 'areas_interes', 'documentos_adjuntos', 'estado_solicitud', 'fecha_solicitud'],
            array_keys($guardada),
        );
        self::assertSame([$correo, 5, 'pendiente'], [$guardada['correo'], $guardada['semestre'], $guardada['estado_solicitud']]);
        self::assertIsArray($guardada['documentos_adjuntos']);
        foreach ($guardada['documentos_adjuntos'] as $documento) {
            self::assertIsArray($documento);
            self::assertStringStartsWith(self::FOLDER . '/', (string) $documento['public_id']);
            self::assertSame(401, self::descargar((string) $documento['url'])[0]);
        }

        // Duplicado mientras está pendiente.
        $this->assertError($this->multipart($datos, [TestImages::subida(TestImages::pdf())]), 409, 'INSCRIPTION_PENDING');

        // Solo administrativos consultan.
        $this->assertError($this->call('GET', '/inscriptions'), 401, 'UNAUTHORIZED');
        $this->assertError($this->call('GET', "/inscriptions/$id", null, $tokenEstudiante), 403, 'FORBIDDEN');
        self::assertContains($id, $this->todas('/inscriptions?estado_solicitud=pendiente', $tokenAdmin)['ids']);

        // Detalle con enlaces firmados que abren.
        $detalle = self::json($this->call('GET', "/inscriptions/$id", null, $tokenAdmin))['inscripcion'];
        self::assertSame(['carta.pdf', 'notas.png'], array_column($detalle['documentos_adjuntos'], 'nombre_archivo'));
        [$status, $cuerpo] = self::descargar($detalle['documentos_adjuntos'][0]['url']);
        self::assertSame([200, '%PDF'], [$status, substr($cuerpo, 0, 4)]);
        [$status, $cuerpo] = self::descargar($detalle['documentos_adjuntos'][1]['url']);
        self::assertSame(200, $status);
        self::assertSame(1600, getimagesizefromstring($cuerpo)[0] ?? null);

        // Aceptar: solo cambia el estado, avisa al aspirante y no crea la cuenta.
        $aceptada = $this->call('PATCH', "/inscriptions/$id", ['estado_solicitud' => 'aceptada'], $tokenAdmin);
        self::assertSame('aceptada', self::json($aceptada)['inscripcion']['estado_solicitud']);
        self::assertSame('aceptada', $this->mongo()->collection(Collections::INSCRIPCIONES)->findOne(['_id' => new ObjectId($id)])['estado_solicitud'] ?? null);
        self::assertSame($correo, $this->mail->enviados[2]['to'] ?? null);
        self::assertSame(0, $this->mongo()->collection(Collections::CREDENCIALES)->countDocuments(['correo' => $correo]));
        $this->assertError($this->call('PATCH', "/inscriptions/$id", ['estado_solicitud' => 'rechazada'], $tokenAdmin), 409, 'INSCRIPTION_CLOSED');
    }

    /**
     * Recorre todas las páginas de un listado y junta ids y estados (para no depender de otros datos de siiis_test).
     *
     * @return array{ids: list<string>, estados: list<string>}
     */
    private function todas(string $path, ?string $token = null): array
    {
        $ids = [];
        $estados = [];
        $separador = str_contains($path, '?') ? '&' : '?';
        for ($page = 1; $page <= 200; ++$page) {
            $body = self::json($this->call('GET', $path . $separador . 'per_page=50&page=' . $page, null, $token));
            foreach ($body['data'] as $item) {
                $ids[] = $item['id'];
                if (isset($item['estado'])) {
                    $estados[$item['estado']] = $item['estado'];
                }
            }
            if ($page >= $body['meta']['total_pages']) {
                break;
            }
        }

        return ['ids' => $ids, 'estados' => array_values($estados)];
    }

    /**
     * @return array{string, string} [id del usuario, token]
     */
    private function cuenta(string $alias, string $rol): array
    {
        $correo = $this->marca . '-' . $alias . '@example.test';
        $credId = new ObjectId();
        $usuarioId = new ObjectId();
        $this->usuarios[] = $usuarioId;
        $this->mongo()->collection(Collections::CREDENCIALES)->insertOne([
            '_id' => $credId, 'correo' => $correo, 'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'usuario_id' => $usuarioId, 'estado_cuenta' => 'activa', 'token_recuperacion' => null,
            'token_expiracion' => null, 'intentos_fallidos' => 0, 'fecha_creacion' => new UTCDateTime(), 'ultimo_acceso' => null,
        ]);
        $this->mongo()->collection(Collections::USUARIOS)->insertOne([
            '_id' => $usuarioId, 'credencial_id' => $credId, 'nombre' => $this->marca . '-' . $alias, 'apellido' => 'IT',
            'rol' => $rol, 'programa_academico' => null, 'semestre' => null, 'foto_perfil' => null, 'biografia' => null,
            'telefono' => null, 'estado' => true, 'fecha_ingreso' => new UTCDateTime(), 'fecha_salida' => null,
        ]);

        $response = $this->call('POST', '/auth/login', ['correo' => $correo, 'password' => self::PASSWORD]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $token = self::json($response)['token'] ?? null;
        self::assertIsString($token);

        return [(string) $usuarioId, $token];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null, ?string $token = null): ResponseInterface
    {
        $request = $this->peticion($method, $path, $token);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, string>               $datos
     * @param list<\Slim\Psr7\UploadedFile> $archivos
     */
    private function multipart(array $datos, array $archivos): ResponseInterface
    {
        return $this->app->handle(
            $this->peticion('POST', '/inscriptions', null)
                ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
                ->withParsedBody($datos)
                ->withUploadedFiles(['documentos' => $archivos]),
        );
    }

    private function peticion(string $method, string $path, ?string $token): ServerRequestInterface
    {
        // IP distinta en cada petición: el rate limiting se prueba aparte.
        $request = (new ServerRequestFactory())->createServerRequest($method, "/api/v1$path", ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)]);

        return $token !== null ? $request->withHeader('Authorization', "Bearer $token") : $request;
    }

    private function assertError(ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($code, self::json($response)['error']['code'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array{int, string}
     */
    private static function descargar(string $url): array
    {
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $cuerpo = curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_string($cuerpo) ? $cuerpo : ''];
    }

    private static function cloudinary(Settings $s): Cloudinary
    {
        return new Cloudinary(['cloud' => [
            'cloud_name' => $s->cloudinaryCloudName,
            'api_key' => $s->cloudinaryApiKey,
            'api_secret' => $s->cloudinaryApiSecret,
        ]]);
    }
}
