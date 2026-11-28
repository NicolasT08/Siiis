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

    public function testSubirFotoConAnchoMaximoDelInicio(): void
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
        // HOME_IMAGEN_MAX_ANCHO_PX (1920 por defecto), sin agrandar las pequeñas, con CLOUDINARY_CALIDAD.
        self::assertSame(['width' => 1920, 'crop' => 'limit', 'quality' => 'auto'], $this->cloudinary->subidas[0]['transformacion']);
        // Queda en Multimedia con los campos del modelo y se ve en la ruta pública.
        $doc = array_values($this->multimedia->items)[0];
        self::assertSame(['_id', 'tipo', 'url', 'public_id', 'seccion', 'descripcion', 'orden', 'fecha_subida'], array_keys($doc));
        $publico = $this->json($this->app->handle($this->request('GET', '/api/v1/home/slider')));
        self::assertSame('Grupo de trabajo', $publico['data'][0]['descripcion']);
    }

    public function testSubirVideoYOrdenPorDefectoAlFinal(): void
    {
        $this->app = $this->authApp(['CLOUDINARY_HOME_FOLDER' => 'siiis_test/home', 'HOME_MAX_VIDEOS' => '2']);
        $token = $this->admin('superadministrador');
        $this->item('video_presentacion', 4, 'video');

        $response = $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'Presentación'], TestImages::subida(TestImages::mp4()));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $item = $this->json($response)['multimedia'];
        self::assertSame(['video', 5], [$item['tipo'], $item['orden']]);
        self::assertStringContainsString('/video/', $item['url']);
        self::assertSame('siiis_test/home/videos', $this->cloudinary->subidas[0]['folder']);
        self::assertCount(1, $this->cloudinary->videosSubidos);
        self::assertStringContainsString('/video/upload/q_auto/', $item['url'], 'El video se entrega con q_auto.');
    }

    public function testTransformacionAlSubirConfigurableDesdeElEnv(): void
    {
        $this->app = $this->authApp([
            'CLOUDINARY_HOME_FOLDER' => 'siiis_test/home',
            'HOME_IMAGEN_MAX_ANCHO_PX' => '1440',
            'HOME_VIDEO_MAX_ANCHO_PX' => '960',
            'CLOUDINARY_CALIDAD' => 'auto:good',
        ]);
        $token = $this->admin();

        self::assertSame(201, $this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'Foto'], TestImages::subida(TestImages::png()))->getStatusCode());
        self::assertSame(201, $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'Video'], TestImages::subida(TestImages::mp4()))->getStatusCode());

        self::assertSame(['width' => 1440, 'crop' => 'limit', 'quality' => 'auto:good'], $this->cloudinary->subidas[0]['transformacion']);
        // El video siempre con q_auto y códec automático (vc_auto); CLOUDINARY_CALIDAD es solo para imágenes.
        self::assertSame(
            ['width' => 960, 'crop' => 'limit', 'quality' => 'auto', 'video_codec' => 'auto'],
            $this->cloudinary->subidas[1]['transformacion'],
        );
    }

    public function testRecursosPreexistentesNuncaSeBorranDeCloudinary(): void
    {
        $logs = $this->storage . '/logs-preexistentes';
        $this->extraOverrides[Logger::class] = new Logger($logs);
        $this->app = $this->authApp(['CLOUDINARY_HOME_FOLDER' => 'siiis_test/home', 'HOME_MAX_ITEMS' => '3']);
        $token = $this->admin();
        // Registrados con cargar_multimedia.php --existentes: public_id fuera de CLOUDINARY_HOME_FOLDER.
        $foto = $this->item('slider_principal', 1, 'imagen', 'Foto_1');
        $otraFoto = $this->item('slider_principal', 2, 'imagen', 'Foto_2');
        $video = $this->item('video_presentacion', 1, 'video', 'video_home');
        // Una carpeta que solo empieza igual tampoco es de la aplicación.
        $parecida = $this->item('slider_principal', 3, 'imagen', 'siiis_test/homeX/foto');

        // Quitar del slider y "Reemplazar video": se borra el registro, no el archivo.
        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$foto", null, self::bearer($token)))->getStatusCode());
        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$parecida", null, self::bearer($token)))->getStatusCode());
        $reemplazo = $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'Nuevo', 'reemplaza' => (string) $video], TestImages::subida(TestImages::mp4()));
        self::assertSame(201, $reemplazo->getStatusCode(), (string) $reemplazo->getBody());
        $reemplazoFoto = $this->subir($token, ['seccion' => 'slider_principal', 'descripcion' => 'Nueva', 'reemplaza' => (string) $otraFoto], TestImages::subida(TestImages::png()));
        self::assertSame(201, $reemplazoFoto->getStatusCode(), (string) $reemplazoFoto->getBody());

        self::assertArrayNotHasKey((string) $foto, $this->multimedia->items);
        self::assertArrayNotHasKey((string) $video, $this->multimedia->items);
        self::assertArrayNotHasKey((string) $otraFoto, $this->multimedia->items);
        self::assertSame([], $this->cloudinary->borrados, 'Foto_1, Foto_2 y video_home siguen en Cloudinary.');
        $log = implode('', array_map('file_get_contents', glob($logs . '/*') ?: []));
        self::assertStringContainsString('video_home', $log);

        // Lo que subió la aplicación (dentro de la carpeta) sí se borra.
        $nuevo = $this->json($reemplazo)['multimedia']['id'];
        self::assertSame(204, $this->app->handle($this->request('DELETE', "/api/v1/multimedia/$nuevo", null, self::bearer($token)))->getStatusCode());
        self::assertSame([$this->cloudinary->videosSubidos[0]], $this->cloudinary->borradosVideo);
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

    public function testSoloUnVideoDePresentacionPorDefecto(): void
    {
        $token = $this->admin();
        $this->item('video_presentacion', 1, 'video');

        $response = $this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'Otro'], TestImages::subida(TestImages::mp4()));

        $this->assertError($response, 409, 'VIDEO_LIMIT');
        self::assertStringContainsString('Reemplazar video', $this->json($response)['error']['message']);
        self::assertSame([], $this->cloudinary->subidas, 'No se gasta una subida si ya hay video.');
        self::assertCount(1, $this->multimedia->items);
    }

    public function testReemplazarVideoBorraElAnteriorSoloSiLaSubidaSaleBien(): void
    {
        $token = $this->admin();
        $anterior = $this->item('video_presentacion', 3, 'video', 'siiis_test/home/videos/viejo');
        $campos = ['seccion' => 'video_presentacion', 'descripcion' => 'Nuevo', 'reemplaza' => (string) $anterior];

        // Si la subida falla, el video anterior sigue intacto.
        $this->cloudinary->fallarSubida = true;
        $this->assertError($this->subir($token, $campos, TestImages::subida(TestImages::mp4())), 502, 'CLOUDINARY_UNAVAILABLE');
        $this->cloudinary->fallarSubida = false;
        self::assertArrayHasKey((string) $anterior, $this->multimedia->items);
        self::assertSame([], $this->cloudinary->borrados);

        $response = $this->subir($token, $campos, TestImages::subida(TestImages::mp4()));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $nuevo = $this->json($response)['multimedia'];
        self::assertSame(['Nuevo', 3], [$nuevo['descripcion'], $nuevo['orden']], 'Toma el lugar del anterior.');
        self::assertSame([$nuevo['id']], array_keys($this->multimedia->items));
        self::assertSame(['siiis_test/home/videos/viejo'], $this->cloudinary->borradosVideo);
    }

    public function testReemplazaDebeSerUnElementoDeLaMismaSeccion(): void
    {
        $token = $this->admin();
        $foto = $this->item('slider_principal', 1);
        $mp4 = static fn (): UploadedFile => TestImages::subida(TestImages::mp4());

        $this->assertError($this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'x', 'reemplaza' => 'abc'], $mp4()), 400, 'VALIDATION_ERROR');
        $this->assertError($this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'x', 'reemplaza' => (string) new ObjectId()], $mp4()), 404, 'NOT_FOUND');
        $this->assertError($this->subir($token, ['seccion' => 'video_presentacion', 'descripcion' => 'x', 'reemplaza' => (string) $foto], $mp4()), 404, 'NOT_FOUND');
        self::assertArrayHasKey((string) $foto, $this->multimedia->items);
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
        $this->app = $this->authApp(['CLOUDINARY_HOME_FOLDER' => 'siiis_test/home']);
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
