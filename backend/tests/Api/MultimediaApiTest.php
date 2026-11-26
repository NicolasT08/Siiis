<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Support\Logger;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\InMemoryMultimediaRepository;
use Siiis\Tests\Support\TestImages;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Administración de fotos del carrusel y videos del inicio (DEC-B62).
 */
final class MultimediaApiTest extends AuthTestCase
{
    private FakeCloudinaryService $cloudinary;
    private InMemoryMultimediaRepository $multimedia;

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudinary = new FakeCloudinaryService();
        $this->multimedia = new InMemoryMultimediaRepository();
        $this->extraOverrides = [CloudinaryService::class => $this->cloudinary, MultimediaRepository::class => $this->multimedia];
        $this->app = $this->authApp(['CLOUDINARY_HOME_FOLDER' => 'siiis_test/home', 'HOME_MAX_ITEMS' => '3']);
    }

    protected function tearDown(): void
    {
        TestImages::limpiar();
        parent::tearDown();
    }

    private function admin(string $rol = 'administrador'): string
    {
        return $this->sesionComo($this->app, $rol)[0];
    }

    /**
     * @param array<string, string> $campos
     */
    private function subir(string $token, array $campos, ?UploadedFile $archivo): ResponseInterface
    {
        $request = $this->request('POST', '/api/v1/multimedia', null, self::bearer($token))->withParsedBody($campos);
        if ($archivo !== null) {
            $request = $request->withUploadedFiles(['archivo' => $archivo]);
        }

        return $this->app->handle($request);
    }

    private function item(string $seccion, int $orden, string $tipo = 'imagen', string $publicId = ''): ObjectId
    {
        $id = new ObjectId();
        $this->multimedia->items[(string) $id] = [
            '_id' => $id,
            'tipo' => $tipo,
            'url' => "https://res.cloudinary.com/demo/{$tipo}/upload/x{$orden}.jpg",
            'public_id' => $publicId,
            'seccion' => $seccion,
            'descripcion' => "Ítem $orden",
            'orden' => $orden,
            'fecha_subida' => new UTCDateTime(),
        ];

        return $id;
    }

    public function testSubirFotoConLasReglasDeLasImagenesAdjuntas(): void
    {
        $token = $this->admin();

        $response = $this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => "  Grupo de\ntrabajo  "], TestImages::subida(TestImages::png()));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $item = $this->json($response)['multimedia'];
        self::assertSame(['imagen', 'slider_principal', 'Grupo de trabajo', 1], [$item['tipo'], $item['seccion'], $item['descripcion'], $item['orden']]);
        self::assertStringContainsString('/image/upload/f_auto,q_auto/', $item['url']);
        self::assertArrayNotHasKey('public_id', $item);
        self::assertSame('siiis_test/home/slider', $this->cloudinary->subidas[0]['folder']);
        self::assertSame(['width' => 1600, 'crop' => 'limit', 'quality' => 'auto'], $this->cloudinary->subidas[0]['transformacion']);
        // Queda en Multimedia con los campos del modelo y se ve en la ruta pública.
        $doc = array_values($this->multimedia->items)[0];
        self::assertSame(['_id', 'tipo', 'url', 'public_id', 'seccion', 'descripcion', 'orden', 'fecha_subida'], array_keys($doc));
        $publico = $this->json($this->app->handle($this->request('GET', '/api/v1/home/slider')));
        self::assertSame('Grupo de trabajo', $publico['data'][0]['descripcion']);
    }

    public function testSubirVideoYOrdenPorDefectoAlFinal(): void
    {
        $token = $this->admin('superadministrador');
        $this->item('video_presentacion', 4, 'video');

        $response = $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'Presentación'], TestImages::subida(TestImages::mp4()));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $item = $this->json($response)['multimedia'];
        self::assertSame(['video', 5], [$item['tipo'], $item['orden']]);
        self::assertStringContainsString('/video/', $item['url']);
        self::assertSame('siiis_test/home/videos', $this->cloudinary->subidas[0]['folder']);
        self::assertCount(1, $this->cloudinary->videosSubidos);
    }

    public function testValidacionesDeLaSubida(): void
    {
        $token = $this->admin();
        $png = static fn (): UploadedFile => TestImages::subida(TestImages::png());

        $this->assertError($this->subir($token, ['descripcion' => 'x'], $png()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'galeria', 'descripcion' => 'x'], $png()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal'], $png()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => str_repeat('a', 201)], $png()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x', 'orden' => '0'], $png()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], null), 400, 'VALIDATION_ERROR');
        // Una foto en la sección de videos (o al revés) es un formato no admitido.
        $this->assertError($this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'x'], $png()), 415, 'UNSUPPORTED_MEDIA_TYPE');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::mp4())), 415, 'UNSUPPORTED_MEDIA_TYPE');
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::disfrazado())), 415, 'UNSUPPORTED_MEDIA_TYPE');
        self::assertSame([], $this->cloudinary->subidas);
        self::assertSame([], $this->multimedia->items);
    }

    public function testLimitesDeTamanoDesdeElEnv(): void
    {
        $this->app = $this->authApp(['MULTIMEDIA_VIDEO_MAX_BYTES' => '1024', 'DOCUMENTO_ADJUNTO_MAX_BYTES' => '2048']);
        $token = $this->admin();

        $video = $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'x'], TestImages::subida(TestImages::mp4(2000)));
        $this->assertError($video, 413, 'FILE_TOO_LARGE');
        self::assertStringContainsString('El video supera el tamaño máximo de 1 KB', $this->json($video)['error']['message']);
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::pngDeTamano(3000))), 413, 'FILE_TOO_LARGE');
    }

    public function testMaximoPorSeccion(): void
    {
        $token = $this->admin();
        foreach ([1, 2, 3] as $orden) {
            $this->item('slider_principal', $orden);
        }

        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::png())), 409, 'MULTIMEDIA_LIMIT');
        self::assertSame([], $this->cloudinary->subidas);
    }

    public function testCloudinaryRechazaOFallaYMongoFallaConCompensacion(): void
    {
        $token = $this->admin();
        $campos = ['seccion' => 'slider_principal', 'descripcion' => 'x'];

        $this->cloudinary->rechazarEnSubida = 1;
        $this->assertError($this->subir($token, $campos, TestImages::subida(TestImages::png())), 415, 'UNSUPPORTED_MEDIA_TYPE');
        $this->cloudinary->rechazarEnSubida = 0;

        $this->cloudinary->fallarSubida = true;
        $this->assertError($this->subir($token, $campos, TestImages::subida(TestImages::png())), 502, 'CLOUDINARY_UNAVAILABLE');
        $this->cloudinary->fallarSubida = false;

        // Si MongoDB falla después de subir, el archivo se borra de Cloudinary.
        $this->multimedia->fallarAlInsertar = true;
        $this->assertError($this->subir($token, $campos, TestImages::subida(TestImages::png())), 500, 'INTERNAL_ERROR');
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borrados);
        self::assertSame([], $this->multimedia->items);
    }

    public function testListarEditarYReordenar(): void
    {
        $token = $this->admin();
        $a = $this->item('slider_principal', 1);
        $b = $this->item('slider_principal', 2);
        $c = $this->item('slider_principal', 3);
        $this->item('video_presentacion', 1, 'video');

        $lista = $this->app->handle($this->request('GET', '/api/v1/multimedia?seccion=slider_principal', null, self::bearer($token)));
        self::assertSame('no-store', $lista->getHeaderLine('Cache-Control'));
        self::assertSame([(string) $a, (string) $b, (string) $c], array_column($this->json($lista)['data'], 'id'));
        $this->assertError($this->app->handle($this->request('GET', '/api/v1/multimedia', null, self::bearer($token))), 400, 'VALIDATION_ERROR');

        $editado = $this->app->handle($this->request('PATCH', "/api/v1/multimedia/$b", ['descripcion' => 'Nueva descripción', 'orden' => 7], self::bearer($token)));
        self::assertSame(200, $editado->getStatusCode(), (string) $editado->getBody());
        self::assertSame(['Nueva descripción', 7], [$this->json($editado)['multimedia']['descripcion'], $this->json($editado)['multimedia']['orden']]);
        $this->assertError($this->app->handle($this->request('PATCH', "/api/v1/multimedia/$b", ['url' => 'https://evil.example/x.jpg'], self::bearer($token))), 400, 'VALIDATION_ERROR');
        $this->assertError($this->app->handle($this->request('PATCH', "/api/v1/multimedia/$b", ['descripcion' => ['$ne' => 1]], self::bearer($token))), 400, 'VALIDATION_ERROR');
        $this->assertError($this->app->handle($this->request('PATCH', '/api/v1/multimedia/650f1c2e8b3a4a0012345678', ['orden' => 1], self::bearer($token))), 404, 'NOT_FOUND');

        $orden = $this->app->handle($this->request('PUT', '/api/v1/multimedia/orden', ['seccion' => 'slider_principal', 'ids' => [(string) $c, (string) $a, (string) $b]], self::bearer($token)));
        self::assertSame(200, $orden->getStatusCode(), (string) $orden->getBody());
        self::assertSame([[(string) $c, 1], [(string) $a, 2], [(string) $b, 3]], array_map(static fn (array $i): array => [$i['id'], $i['orden']], $this->json($orden)['data']));

        // Faltan elementos, sobran, se repiten o son de otra sección.
        foreach ([[(string) $c, (string) $a], [(string) $c, (string) $a, (string) $b, (string) new ObjectId()], [(string) $c, (string) $c, (string) $a]] as $ids) {
            $this->assertError($this->app->handle($this->request('PUT', '/api/v1/multimedia/orden', ['seccion' => 'slider_principal', 'ids' => $ids], self::bearer($token))), 400, 'VALIDATION_ERROR');
        }
    }

    public function testBorrarEliminaElRegistroYElArchivo(): void
    {
        $token = $this->admin();
        $foto = $this->item('slider_principal', 1, 'imagen', 'siiis_test/home/slider/foto');
        $video = $this->item('video_presentacion', 1, 'video', 'siiis_test/home/videos/intro');

        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$foto", null, self::bearer($token)))->getStatusCode());
        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$video", null, self::bearer($token)))->getStatusCode());

        self::assertSame([], $this->multimedia->items);
        self::assertSame(['siiis_test/home/slider/foto', 'siiis_test/home/videos/intro'], $this->cloudinary->borrados);
        self::assertSame(['siiis_test/home/videos/intro'], $this->cloudinary->borradosVideo, 'El video se borra como recurso de video.');
        $this->assertError($this->app->handle($this->request('DELETE', "/api/v1/multimedia/$foto", null, self::bearer($token))), 404, 'NOT_FOUND');
    }

    public function testSiCloudinaryNoBorraQuedaEnElLog(): void
    {
        $logs = $this->storage . '/logs-multimedia';
        $this->extraOverrides[Logger::class] = new Logger($logs);
        $this->app = $this->authApp();
        $token = $this->admin();
        $foto = $this->item('slider_principal', 1, 'imagen', 'siiis_test/home/slider/huerfana');
        $this->cloudinary->fallarBorrado = true;

        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$foto", null, self::bearer($token)))->getStatusCode());
        $log = implode('', array_map('file_get_contents', glob($logs . '/*') ?: []));
        self::assertStringContainsString('siiis_test/home/slider/huerfana', $log);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rutas(): iterable
    {
        yield 'listar' => ['GET', '/api/v1/multimedia?seccion=slider_principal', ''];
        yield 'subir' => ['POST', '/api/v1/multimedia', ''];
        yield 'editar' => ['PATCH', '/api/v1/multimedia/650f1c2e8b3a4a0012345678', '{"orden":1}'];
        yield 'reordenar' => ['PUT', '/api/v1/multimedia/orden', '{"seccion":"slider_principal","ids":["650f1c2e8b3a4a0012345678"]}'];
        yield 'borrar' => ['DELETE', '/api/v1/multimedia/650f1c2e8b3a4a0012345678', ''];
    }

    #[DataProvider('rutas')]
    public function testSoloSuperadministradorYAdministrador(string $metodo, string $ruta, string $json): void
    {
        $body = $json === '' ? null : json_decode($json, true);
        $this->assertError($this->app->handle($this->request($metodo, $ruta, $body)), 401, 'UNAUTHORIZED');
        foreach (['docente_director', 'estudiante_lider', 'estudiante'] as $rol) {
            $token = $this->sesionComo($this->app, $rol)[0];
            $this->assertError($this->app->handle($this->request($metodo, $ruta, $body, self::bearer($token))), 403, 'FORBIDDEN');
        }
    }

    public function testRateLimitingDeSubidas(): void
    {
        $this->app = $this->authApp(['RATE_LIMIT_MULTIMEDIA_MAX' => '1']);
        $token = $this->admin();

        $this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::png()));
        $this->assertError($this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'x'], TestImages::subida(TestImages::png())), 429, 'RATE_LIMITED');
    }
}
