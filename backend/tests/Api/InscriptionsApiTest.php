<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Services\CloudinaryService;
use Siiis\Services\MailService;
use Siiis\Support\Logger;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\FakeMailService;
use Siiis\Tests\Support\TestImages;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Inscripciones (DEC-B47 a DEC-B52) con Cloudinary y correo simulados: POST /inscriptions público y
 * GET/PATCH para administrativos.
 */
final class InscriptionsApiTest extends AuthTestCase
{
    private const DESTINO = 'semillero@uptc.edu.co';

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    private FakeCloudinaryService $cloudinary;

    private FakeMailService $mail;

    private int $segundos = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudinary = new FakeCloudinaryService();
        $this->mail = new FakeMailService();
        $this->extraOverrides = [CloudinaryService::class => $this->cloudinary, MailService::class => $this->mail];
        $this->app = $this->authApp(['CONTACTO_CORREO_DESTINO' => self::DESTINO]);
    }

    protected function tearDown(): void
    {
        TestImages::limpiar();
        parent::tearDown();
    }

    public function testAspiranteEnviaSolicitudConDocumentosPrivados(): void
    {
        $response = $this->enviar([
            'nombre_completo' => "  Laura\n Martínez ",
            'correo' => ' Laura.Martinez@UPTC.edu.co ',
            'telefono' => '+57 310-555 1234',
            'semestre' => '4',
            'estado_solicitud' => 'aceptada',
            'documentos_adjuntos' => 'x',
        ] + self::datos(), [
            TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf', 'Carta firmada.PDF'),
            TestImages::subida(TestImages::png(), UPLOAD_ERR_OK, 'image/png', '../../certificado.png'),
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertIsString($body['mensaje']);
        $inscripcion = $body['inscripcion'];
        self::assertSame('Laura Martínez', $inscripcion['nombre_completo']);
        self::assertSame('laura.martinez@uptc.edu.co', $inscripcion['correo']);
        self::assertSame('+573105551234', $inscripcion['telefono']);
        self::assertSame(4, $inscripcion['semestre']);
        self::assertSame('pendiente', $inscripcion['estado_solicitud']);
        self::assertSame('2026-09-25T12:00:00Z', $inscripcion['fecha_solicitud']);
        // Al aspirante no se le devuelven URL ni public_id.
        self::assertSame([['nombre_archivo' => 'Carta firmada.pdf'], ['nombre_archivo' => 'certificado.png']], $inscripcion['documentos_adjuntos']);
        self::assertStringNotContainsString('cloudinary', (string) $response->getBody());

        // Guardado con los campos exactos del modelo (spec 8.2).
        $guardada = $this->db->inscripciones[$inscripcion['id']];
        self::assertSame(
            ['_id', 'nombre_completo', 'correo', 'telefono', 'programa_academico', 'semestre', 'carta_motivacion', 'documentos_adjuntos', 'estado_solicitud', 'fecha_solicitud'],
            array_keys($guardada),
        );
        self::assertInstanceOf(UTCDateTime::class, $guardada['fecha_solicitud']);
        self::assertSame('Ingeniería de Sistemas', $guardada['programa_academico']);

        // Subidos privados, en la carpeta de inscripciones, con las reglas de los adjuntos (DEC-B42, DEC-B50).
        self::assertCount(2, $this->cloudinary->subidas);
        [$pdf, $png] = $this->cloudinary->subidas;
        foreach ([$pdf, $png] as $subida) {
            self::assertTrue($subida['privado']);
            self::assertSame('siiis/inscripciones', $subida['folder']);
            self::assertSame(['jpg', 'png', 'webp', 'pdf'], $subida['formatos']);
        }
        self::assertSame([], $pdf['transformacion']);
        self::assertSame(['width' => 1600, 'crop' => 'limit', 'quality' => 'auto'], $png['transformacion']);
        self::assertSame([
            ['url' => 'https://res.cloudinary.com/demo/image/authenticated/' . $pdf['public_id'] . '.pdf', 'public_id' => $pdf['public_id'], 'nombre_archivo' => 'Carta firmada.pdf'],
            ['url' => 'https://res.cloudinary.com/demo/image/authenticated/' . $png['public_id'] . '.png', 'public_id' => $png['public_id'], 'nombre_archivo' => 'certificado.png'],
        ], $guardada['documentos_adjuntos']);

        // Correos: confirmación al aspirante y aviso al semillero (DEC-B52).
        self::assertSame(['laura.martinez@uptc.edu.co', self::DESTINO], array_column($this->mail->enviados, 'to'));
        self::assertStringContainsString('Laura Martínez', $this->mail->enviados[0]['html']);
        self::assertStringContainsString('Nueva solicitud', $this->mail->enviados[1]['subject']);
    }

    public function testUnSoloArchivoSinCorchetesYAvisoConfigurable(): void
    {
        $app = $this->authApp(['INSCRIPCION_CORREO_AVISO' => 'aviso@uptc.edu.co', 'CONTACTO_CORREO_DESTINO' => self::DESTINO]);

        $response = $app->handle($this->peticion(self::datos(), TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf')));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->cloudinary->subidas);
        self::assertSame('aviso@uptc.edu.co', $this->mail->enviados[1]['to']);
    }

    public function testTelefonoOpcional(): void
    {
        $datos = self::datos();
        unset($datos['telefono']);

        $response = $this->enviar($datos);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->json($response)['inscripcion']['telefono']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function datosInvalidos(): iterable
    {
        $sin = static function (string $campo): array {
            $datos = self::datos();
            unset($datos[$campo]);

            return $datos;
        };

        yield 'vacío' => [[], ['nombre_completo', 'correo', 'programa_academico', 'semestre', 'carta_motivacion', 'acepta_tratamiento_datos']];
        foreach (['nombre_completo', 'correo', 'programa_academico', 'semestre', 'carta_motivacion', 'acepta_tratamiento_datos'] as $campo) {
            yield 'sin ' . $campo => [$sin($campo), [$campo]];
        }
        yield 'correo de otro dominio' => [['correo' => 'laura@gmail.com'] + self::datos(), ['correo']];
        yield 'correo de subdominio parecido' => [['correo' => 'laura@uptc.edu.co.evil.com'] + self::datos(), ['correo']];
        yield 'correo inválido' => [['correo' => 'laura'] + self::datos(), ['correo']];
        yield 'nombre largo' => [['nombre_completo' => str_repeat('a', 121)] + self::datos(), ['nombre_completo']];
        yield 'carta corta' => [['carta_motivacion' => str_repeat('a', 99)] + self::datos(), ['carta_motivacion']];
        yield 'carta larga' => [['carta_motivacion' => str_repeat('ñ', 3001)] + self::datos(), ['carta_motivacion']];
        yield 'semestre 0' => [['semestre' => '0'] + self::datos(), ['semestre']];
        yield 'semestre 11' => [['semestre' => '11'] + self::datos(), ['semestre']];
        yield 'semestre texto' => [['semestre' => 'cuarto'] + self::datos(), ['semestre']];
        yield 'semestre decimal' => [['semestre' => '4.5'] + self::datos(), ['semestre']];
        yield 'teléfono inválido' => [['telefono' => '12ab'] + self::datos(), ['telefono']];
        yield 'programa largo' => [['programa_academico' => str_repeat('p', 121)] + self::datos(), ['programa_academico']];
        yield 'no acepta tratamiento' => [['acepta_tratamiento_datos' => 'false'] + self::datos(), ['acepta_tratamiento_datos']];
        yield 'acepta como "si"' => [['acepta_tratamiento_datos' => 'si'] + self::datos(), ['acepta_tratamiento_datos']];
        yield 'inyección en correo' => [['correo' => ['$ne' => '']] + self::datos(), ['correo']];
        yield 'inyección en nombre' => [['nombre_completo' => ['$gt' => '']] + self::datos(), ['nombre_completo']];
        yield 'inyección en semestre' => [['semestre' => ['$gt' => 0]] + self::datos(), ['semestre']];
        // Formularios en Latin-1 (p. ej. curl desde la consola de Windows): 400, nunca un 500 de MongoDB.
        yield 'nombre en Latin-1' => [['nombre_completo' => "Mar\xEDa"] + self::datos(), ['nombre_completo']];
        yield 'programa en Latin-1' => [['programa_academico' => "Ingenier\xEDa"] + self::datos(), ['programa_academico']];
        yield 'carta en Latin-1' => [['carta_motivacion' => str_repeat("investigaci\xF3n ", 20)] + self::datos(), ['carta_motivacion']];
        yield 'teléfono en Latin-1' => [['telefono' => "310\xA0555"] + self::datos(), ['telefono']];
    }

    /**
     * @param array<string, mixed> $datos
     * @param list<string>         $campos
     */
    #[DataProvider('datosInvalidos')]
    public function testValidacionDeDatos(array $datos, array $campos): void
    {
        $response = $this->enviar($datos);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($campos, array_keys($this->json($response)['error']['fields']));
        $this->assertNadaGuardado();
    }

    public function testSemestreEnteroJsonTambienSirve(): void
    {
        self::assertSame(201, $this->enviar(['semestre' => 10] + self::datos())->getStatusCode());
    }

    public function testDominiosDeCorreoConfigurables(): void
    {
        $abierta = $this->authApp(['INSCRIPCION_DOMINIOS_CORREO' => '']);
        $response = $abierta->handle($this->peticion(['correo' => 'laura@gmail.com'] + self::datos(), [TestImages::subida(TestImages::pdf())]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $varios = $this->authApp(['INSCRIPCION_DOMINIOS_CORREO' => 'uptc.edu.co, @Gmail.com']);
        $response = $varios->handle($this->peticion(['correo' => 'otra@gmail.com'] + self::datos(), [TestImages::subida(TestImages::pdf())]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $response = $varios->handle($this->peticion(['correo' => 'otra@hotmail.com'] + self::datos(), [TestImages::subida(TestImages::pdf())]));
        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame('Usa tu correo institucional (@uptc.edu.co, @gmail.com).', $this->json($response)['error']['fields']['correo']);
    }

    public function testSinDocumentos(): void
    {
        $response = $this->app->handle($this->peticion(self::datos(), null));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('documentos', $this->json($response)['error']['fields']);
        $this->assertNadaGuardado();
    }

    public function testDocumentoVacioEnElFormularioCuentaComoAusente(): void
    {
        $response = $this->enviar(self::datos(), [TestImages::subida(TestImages::png(), UPLOAD_ERR_NO_FILE)]);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('documentos', $this->json($response)['error']['fields']);
    }

    public function testMasDeTresDocumentos(): void
    {
        $archivos = array_map(static fn (): UploadedFile => TestImages::subida(TestImages::pdf()), range(1, 4));

        $response = $this->enviar(self::datos(), $archivos);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame('Puedes adjuntar como máximo 3 archivos.', $this->json($response)['error']['fields']['documentos']);
        $this->assertNadaGuardado();
    }

    public function testLimiteDeDocumentosConfigurable(): void
    {
        $app = $this->authApp(['INSCRIPCION_DOCUMENTOS_MAX' => '1']);

        $response = $app->handle($this->peticion(self::datos(), [TestImages::subida(TestImages::pdf()), TestImages::subida(TestImages::pdf())]));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
    }

    public function testArchivoDisfrazadoNoSeSube(): void
    {
        $response = $this->enviar(self::datos(), [
            TestImages::subida(TestImages::pdf()),
            TestImages::subida(TestImages::disfrazado(), UPLOAD_ERR_OK, 'application/pdf', 'carta.pdf'),
        ]);

        $this->assertError($response, 415, 'UNSUPPORTED_MEDIA_TYPE');
        $this->assertNadaGuardado();
    }

    public function testPdfMayorDeTresMegasYImagenDeCincoMegas(): void
    {
        $grande = $this->enviar(self::datos(), [TestImages::subida(TestImages::pdfDeTamano(3145729), UPLOAD_ERR_OK, 'application/pdf')]);
        $this->assertError($grande, 413, 'FILE_TOO_LARGE');
        $this->assertNadaGuardado();

        $imagen = $this->enviar(self::datos(), [TestImages::subida(TestImages::pngDeTamano(5242880))]);
        self::assertSame(201, $imagen->getStatusCode(), (string) $imagen->getBody());
    }

    public function testErrorDeSubidaDePhp(): void
    {
        $response = $this->enviar(self::datos(), [TestImages::subida(TestImages::pdf(), UPLOAD_ERR_INI_SIZE)]);

        $this->assertError($response, 413, 'FILE_TOO_LARGE');
    }

    public function testSolicitudPendienteDuplicada(): void
    {
        $this->inscripcion(['correo' => 'laura@uptc.edu.co', 'estado_solicitud' => 'pendiente']);

        $response = $this->enviar(['correo' => 'LAURA@uptc.edu.co'] + self::datos());

        $this->assertError($response, 409, 'INSCRIPTION_PENDING');
        self::assertSame([], $this->cloudinary->subidas);
        self::assertCount(1, $this->db->inscripciones);
    }

    public function testNuevaSolicitudTrasUnaRechazada(): void
    {
        $this->inscripcion(['correo' => 'laura@uptc.edu.co', 'estado_solicitud' => 'rechazada']);

        self::assertSame(201, $this->enviar(['correo' => 'laura@uptc.edu.co'] + self::datos())->getStatusCode());
        self::assertCount(2, $this->db->inscripciones);
    }

    public function testFalloEnLaSegundaSubidaBorraLaPrimera(): void
    {
        $this->cloudinary->fallarEnSubida = 2;

        $response = $this->enviar(self::datos(), [TestImages::subida(TestImages::pdf()), TestImages::subida(TestImages::png())]);

        $this->assertError($response, 502, 'CLOUDINARY_UNAVAILABLE');
        self::assertCount(1, $this->cloudinary->subidas);
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borradosPrivados);
        self::assertSame([], $this->db->inscripciones);
        self::assertSame([], $this->mail->enviados);
    }

    public function testDocumentoRechazadoPorCloudinaryResponde415YBorraLosAnteriores(): void
    {
        $this->cloudinary->rechazarEnSubida = 2;

        $response = $this->enviar(self::datos(), [TestImages::subida(TestImages::pdf()), TestImages::subida(TestImages::png())]);

        $this->assertError($response, 415, 'UNSUPPORTED_MEDIA_TYPE');
        self::assertStringContainsString('dañado', $this->json($response)['error']['message']);
        self::assertCount(1, $this->cloudinary->subidas);
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borradosPrivados);
        self::assertSame([], $this->db->inscripciones);
        self::assertSame([], $this->mail->enviados);
    }

    public function testFalloEnMongoBorraTodosLosDocumentos(): void
    {
        $this->inscripciones->fallarAlInsertar = true;

        $response = $this->enviar(self::datos(), [TestImages::subida(TestImages::pdf()), TestImages::subida(TestImages::png())]);

        $this->assertError($response, 500, 'INTERNAL_ERROR');
        self::assertSame(array_column($this->cloudinary->subidas, 'public_id'), $this->cloudinary->borradosPrivados);
        self::assertSame([], $this->mail->enviados);
    }

    public function testSiFallaLaCompensacionQuedaEnElLog(): void
    {
        $logs = $this->storage . '/logs-compensacion';
        $this->extraOverrides[Logger::class] = new Logger($logs);
        $app = $this->authApp();
        $this->cloudinary->fallarEnSubida = 2;
        $this->cloudinary->fallarBorrado = true;

        $response = $app->handle($this->peticion(self::datos(), [TestImages::subida(TestImages::pdf()), TestImages::subida(TestImages::png())]));

        $this->assertError($response, 502, 'CLOUDINARY_UNAVAILABLE');
        $log = implode('', array_map('file_get_contents', glob($logs . '/*') ?: []));
        self::assertStringContainsString('Documento de inscripción huérfano en Cloudinary', $log);
        self::assertStringContainsString($this->cloudinary->subidas[0]['public_id'], $log);
    }

    public function testSiElCorreoFallaLaSolicitudQuedaGuardada(): void
    {
        $logs = $this->storage . '/logs-correo';
        $this->extraOverrides = [
            CloudinaryService::class => $this->cloudinary,
            MailService::class => new FakeMailService(true),
            Logger::class => new Logger($logs),
        ];
        $app = $this->authApp();

        $response = $app->handle($this->peticion(self::datos(), [TestImages::subida(TestImages::pdf())]));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->db->inscripciones);
        $log = implode('', array_map('file_get_contents', glob($logs . '/*') ?: []));
        self::assertSame(2, substr_count($log, 'No se pudo enviar un correo de inscripción'));
        self::assertStringContainsString((string) array_key_first($this->db->inscripciones), $log);
    }

    public function testRateLimitPorIp(): void
    {
        $app = $this->authApp(['RATE_LIMIT_INSCRIPTIONS_MAX' => '2']);

        self::assertSame(201, $app->handle($this->peticion(self::datos(), [TestImages::subida(TestImages::pdf())]))->getStatusCode());
        $this->assertError($app->handle($this->peticion([], [TestImages::subida(TestImages::pdf())])), 400, 'VALIDATION_ERROR');
        $bloqueada = $app->handle($this->peticion(['correo' => 'otra@uptc.edu.co'] + self::datos(), [TestImages::subida(TestImages::pdf())]));

        $this->assertError($bloqueada, 429, 'RATE_LIMITED');
        self::assertSame('3600', $bloqueada->getHeaderLine('Retry-After'));
        self::assertCount(1, $this->db->inscripciones);
    }

    public function testLimitePorDefectoEsVeintePorHora(): void
    {
        $s = $this->settings();

        self::assertSame(20, $s->rateLimitInscriptionsMax);
        self::assertSame(3600, $s->rateLimitInscriptionsWindowSeconds);
        self::assertSame(['uptc.edu.co'], $s->inscripcionDominiosCorreo);
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
    public function testListadoParaAdministrativos(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $aceptada = $this->inscripcion(['estado_solicitud' => 'aceptada']);
        $pendiente1 = $this->inscripcion([]);
        $pendiente2 = $this->inscripcion([]);

        $response = $this->get('/api/v1/inscriptions', $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame([(string) $pendiente2, (string) $pendiente1, (string) $aceptada], array_column($body['data'], 'id'));
        self::assertSame(
            ['id', 'nombre_completo', 'correo', 'telefono', 'programa_academico', 'semestre', 'estado_solicitud', 'fecha_solicitud'],
            array_keys($body['data'][0]),
        );
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 3, 'total_pages' => 1], $body['meta']);

        $filtrado = $this->json($this->get('/api/v1/inscriptions?estado_solicitud=pendiente&per_page=1&page=2', $token));
        self::assertSame([(string) $pendiente1], array_column($filtrado['data'], 'id'));
        self::assertSame(2, $filtrado['meta']['total']);
    }

    public function testListadoQueryInvalida(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        foreach (['estado_solicitud=borrada', 'estado_solicitud[$ne]=x', 'per_page=51', 'page=0', 'page=abc'] as $query) {
            $this->assertError($this->get('/api/v1/inscriptions?' . $query, $token), 400, 'VALIDATION_ERROR');
        }
    }

    #[DataProvider('administrativos')]
    public function testDetalleConEnlacesFirmadosQueVencen(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->inscripcion([
            'documentos_adjuntos' => [
                ['url' => 'https://res.cloudinary.com/demo/image/authenticated/v1/siiis/inscripciones/abc.pdf', 'public_id' => 'siiis/inscripciones/abc', 'nombre_archivo' => 'carta.pdf'],
                ['url' => 'https://res.cloudinary.com/demo/image/authenticated/v1/siiis/inscripciones/def.jpg', 'public_id' => 'siiis/inscripciones/def', 'nombre_archivo' => 'foto.jpg'],
            ],
        ]);

        $response = $this->get('/api/v1/inscriptions/' . $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $inscripcion = $this->json($response)['inscripcion'];
        self::assertSame(str_repeat('Quiero aprender. ', 10), $inscripcion['carta_motivacion']);
        $expira = $this->clock->now()->getTimestamp() + 3600;
        self::assertSame([
            [
                'nombre_archivo' => 'carta.pdf',
                'formato' => 'pdf',
                'url' => 'https://api.cloudinary.com/v1_1/demo/image/download?public_id=siiis%2Finscripciones%2Fabc&format=pdf&expires_at=' . $expira . '&signature=firma',
                'expira' => '2026-09-25T13:00:00Z',
            ],
            [
                'nombre_archivo' => 'foto.jpg',
                'formato' => 'jpg',
                'url' => 'https://api.cloudinary.com/v1_1/demo/image/download?public_id=siiis%2Finscripciones%2Fdef&format=jpg&expires_at=' . $expira . '&signature=firma',
                'expira' => '2026-09-25T13:00:00Z',
            ],
        ], $inscripcion['documentos_adjuntos']);
        // Nunca la URL guardada ni el public_id como campo.
        self::assertStringNotContainsString('image/authenticated', (string) $response->getBody());
        self::assertStringNotContainsString('"public_id"', (string) $response->getBody());
    }

    public function testDuracionDelEnlaceConfigurable(): void
    {
        $app = $this->authApp(['INSCRIPCION_ENLACE_TTL_SECONDS' => '600']);
        [$token] = $this->sesionComo($app, 'administrador');
        $id = $this->inscripcion([]);

        $response = $app->handle($this->request('GET', '/api/v1/inscriptions/' . $id, null, self::bearer($token)));

        self::assertSame('2026-09-25T12:10:00Z', $this->json($response)['inscripcion']['documentos_adjuntos'][0]['expira']);
    }

    public function testDetalleInexistente(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->get('/api/v1/inscriptions/' . new ObjectId(), $token), 404, 'NOT_FOUND');
        $this->assertError($this->get('/api/v1/inscriptions/no-es-id', $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('administrativos')]
    public function testAceptarSoloCambiaElEstadoYAvisaAlAspirante(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $usuariosAntes = count($this->db->usuarios);
        $id = $this->inscripcion(['correo' => 'laura@uptc.edu.co']);

        $response = $this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'aceptada', 'correo' => 'otra@uptc.edu.co'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $inscripcion = $this->json($response)['inscripcion'];
        self::assertSame('aceptada', $inscripcion['estado_solicitud']);
        self::assertSame('laura@uptc.edu.co', $inscripcion['correo']);
        self::assertSame('aceptada', $this->db->inscripciones[(string) $id]['estado_solicitud']);
        // Aceptar no crea la cuenta (DEC-B52).
        self::assertCount($usuariosAntes, $this->db->usuarios);
        self::assertCount(1, $this->mail->enviados);
        self::assertSame('laura@uptc.edu.co', $this->mail->enviados[0]['to']);
        self::assertStringContainsString('aceptada', $this->mail->enviados[0]['subject']);

        // Ya resuelta: sin vuelta atrás.
        $otra = $this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'rechazada'], $token);
        $this->assertError($otra, 409, 'INSCRIPTION_CLOSED');
        self::assertSame('aceptada', $this->db->inscripciones[(string) $id]['estado_solicitud']);
        self::assertCount(1, $this->mail->enviados);
    }

    public function testRechazarAvisaAlAspirante(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->inscripcion([]);

        $response = $this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'rechazada'], $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rechazada', $this->db->inscripciones[(string) $id]['estado_solicitud']);
        self::assertStringContainsString('no fue aceptada', $this->mail->enviados[0]['text']);
    }

    public function testResolucionSimultanea(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->inscripcion([]);
        $this->inscripciones->resolverAntesCon = 'rechazada';

        $response = $this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'aceptada'], $token);

        $this->assertError($response, 409, 'INSCRIPTION_CLOSED');
        self::assertSame('rechazada', $this->db->inscripciones[(string) $id]['estado_solicitud']);
        self::assertSame([], $this->mail->enviados);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function resolucionesInvalidas(): iterable
    {
        yield 'sin estado' => [(object) []];
        yield 'volver a pendiente' => [['estado_solicitud' => 'pendiente']];
        yield 'otro valor' => [['estado_solicitud' => 'aprobada']];
        yield 'arreglo' => [['estado_solicitud' => ['$ne' => 'x']]];
    }

    #[DataProvider('resolucionesInvalidas')]
    public function testResolucionInvalida(mixed $body): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->inscripcion([]);

        $response = $this->send('PATCH', '/api/v1/inscriptions/' . $id, $body, $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('estado_solicitud', $this->json($response)['error']['fields']);
        self::assertSame('pendiente', $this->db->inscripciones[(string) $id]['estado_solicitud']);
    }

    public function testResolverInexistente(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->send('PATCH', '/api/v1/inscriptions/' . new ObjectId(), ['estado_solicitud' => 'aceptada'], $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('noAdministrativos')]
    public function testSoloAdministrativosConsultanYResuelven(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->inscripcion([]);

        $this->assertError($this->get('/api/v1/inscriptions', $token), 403, 'FORBIDDEN');
        $this->assertError($this->get('/api/v1/inscriptions/' . $id, $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'aceptada'], $token), 403, 'FORBIDDEN');
        self::assertSame('pendiente', $this->db->inscripciones[(string) $id]['estado_solicitud']);
    }

    public function testSinSesion(): void
    {
        $id = $this->inscripcion([]);

        $this->assertError($this->get('/api/v1/inscriptions'), 401, 'UNAUTHORIZED');
        $this->assertError($this->get('/api/v1/inscriptions/' . $id), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('PATCH', '/api/v1/inscriptions/' . $id, ['estado_solicitud' => 'aceptada']), 401, 'UNAUTHORIZED');
    }

    /**
     * @return array<string, string>
     */
    private static function datos(): array
    {
        return [
            'nombre_completo' => 'Laura Martínez',
            'correo' => 'laura@uptc.edu.co',
            'telefono' => '3105551234',
            'programa_academico' => 'Ingeniería de Sistemas',
            'semestre' => '4',
            'carta_motivacion' => str_repeat('Quiero aprender investigación. ', 5),
            'acepta_tratamiento_datos' => 'true',
        ];
    }

    /**
     * @param array<string, mixed>   $datos
     * @param list<UploadedFile>|null $archivos null = un PDF
     */
    private function enviar(array $datos, ?array $archivos = null): ResponseInterface
    {
        return $this->app->handle($this->peticion($datos, $archivos ?? [TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf')]));
    }

    /**
     * Petición `multipart/form-data` como la arma Slim a partir de $_POST y $_FILES.
     *
     * @param array<string, mixed>                  $datos
     * @param list<UploadedFile>|UploadedFile|null $archivos
     */
    private function peticion(array $datos, array|UploadedFile|null $archivos): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->request('POST', '/api/v1/inscriptions')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withParsedBody($datos);

        return $archivos === null ? $request : $request->withUploadedFiles(['documentos' => $archivos]);
    }

    private function get(string $path, ?string $token = null): ResponseInterface
    {
        return $this->send('GET', $path, null, $token);
    }

    private function send(string $method, string $path, mixed $body = null, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request($method, $path, $body, $token === null ? [] : self::bearer($token)));
    }

    private function assertNadaGuardado(): void
    {
        self::assertSame([], $this->cloudinary->subidas);
        self::assertSame([], $this->db->inscripciones);
        self::assertSame([], $this->mail->enviados);
    }

    /**
     * Inserta una solicitud directamente; cada una un segundo más reciente que la anterior.
     *
     * @param array<string, mixed> $campos
     */
    private function inscripcion(array $campos): ObjectId
    {
        $id = new ObjectId();
        $this->db->inscripciones[(string) $id] = $campos + [
            '_id' => $id,
            'nombre_completo' => 'Aspirante de prueba',
            'correo' => 'aspirante-' . bin2hex(random_bytes(3)) . '@uptc.edu.co',
            'telefono' => null,
            'programa_academico' => 'Ingeniería de Sistemas',
            'semestre' => 3,
            'carta_motivacion' => str_repeat('Quiero aprender. ', 10),
            'documentos_adjuntos' => [
                ['url' => 'https://res.cloudinary.com/demo/image/authenticated/v1/siiis/inscripciones/zzz.pdf', 'public_id' => 'siiis/inscripciones/zzz', 'nombre_archivo' => 'carta.pdf'],
            ],
            'estado_solicitud' => 'pendiente',
            'fecha_solicitud' => new UTCDateTime(($this->clock->now()->getTimestamp() + ++$this->segundos) * 1000),
        ];

        return $id;
    }
}
