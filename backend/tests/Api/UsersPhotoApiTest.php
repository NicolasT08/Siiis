<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Services\CloudinaryService;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\TestImages;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * POST /users/{id}/photo (RF-06, DEC-B29) con Cloudinary simulado.
 */
final class UsersPhotoApiTest extends AuthTestCase
{
    private FakeCloudinaryService $cloudinary;

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudinary = new FakeCloudinaryService();
        $this->extraOverrides = [
            CloudinaryService::class => $this->cloudinary,
        ];
        $this->app = $this->authApp();
    }

    protected function tearDown(): void
    {
        TestImages::limpiar();
        parent::tearDown();
    }

    public function testUnIntegranteCambiaSuFotoYSeBorraLaAnterior(): void
    {
        // crearCuenta deja una foto previa con public_id "ana".
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->subir((string) $id, TestImages::subida(TestImages::png()), $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertCount(1, $this->cloudinary->subidas);
        $subida = $this->cloudinary->subidas[0];
        self::assertSame('siiis/perfiles', $subida['folder']);

        // DEC-B42: recorte cuadrado de 400 px y calidad automática al subir.
        self::assertSame(['jpg', 'png', 'webp'], $subida['formatos']);
        self::assertSame(
            ['width' => 400, 'height' => 400, 'crop' => 'fill', 'gravity' => 'auto', 'quality' => 'auto'],
            $subida['transformacion'],
        );

        $usuario = $this->json($response)['usuario'];
        // En MongoDB queda el secure_url de Cloudinary; la API lo entrega con f_auto,q_auto.
        $guardada = 'https://res.cloudinary.com/demo/image/upload/' . $subida['public_id'] . '.png';
        $url = 'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/' . $subida['public_id'] . '.png';
        self::assertSame(['url' => $url], $usuario['foto_perfil']);
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
        self::assertSame(['url' => $guardada, 'public_id' => $subida['public_id']], $this->db->usuarios[(string) $id]['foto_perfil']);
        self::assertSame(['ana'], $this->cloudinary->borrados);

        // La vista pública también la muestra (solo la URL).
        $publico = $this->json($this->app->handle($this->request('GET', '/api/v1/users/' . $id)))['usuario'];
        self::assertSame(['url' => $url], $publico['foto_perfil']);
    }

    public function testTransformacionConfigurable(): void
    {
        $app = $this->authApp(['PERFIL_FOTO_LADO_PX' => '256', 'CLOUDINARY_CALIDAD' => 'auto:eco']);
        [$token, $id] = $this->sesionComo($app, 'estudiante');

        $request = $this->request('POST', '/api/v1/users/' . $id . '/photo', null, self::bearer($token))
            ->withUploadedFiles(['foto' => TestImages::subida(TestImages::png())]);

        self::assertSame(200, $app->handle($request)->getStatusCode());
        self::assertSame(
            ['width' => 256, 'height' => 256, 'crop' => 'fill', 'gravity' => 'auto', 'quality' => 'auto:eco'],
            $this->cloudinary->subidas[0]['transformacion'],
        );
    }

    public function testSinFotoPreviaNoBorraNada(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante', ['foto_perfil' => null]);

        self::assertSame(200, $this->subir((string) $id, TestImages::subida(TestImages::webp()), $token)->getStatusCode());
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testCarpetaConfigurable(): void
    {
        $app = $this->authApp(['CLOUDINARY_PERFILES_FOLDER' => '/siiis_test/perfiles/']);
        [$token, $id] = $this->sesionComo($app, 'estudiante');

        $response = $app->handle($this->peticion((string) $id, TestImages::subida(TestImages::jpeg()), $token));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('siiis_test/perfiles', $this->cloudinary->subidas[0]['folder']);
    }

    public function testAdministradorCambiaLaFotoDeUnEstudiante(): void
    {
        [, $estudiante] = $this->sesionComo($this->app, 'estudiante');
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->subir((string) $estudiante, TestImages::subida(TestImages::png()), $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            $this->cloudinary->subidas[0]['public_id'],
            $this->db->usuarios[(string) $estudiante]['foto_perfil']['public_id'],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sinPermiso(): iterable
    {
        yield 'estudiante sobre otro estudiante' => ['estudiante', 'estudiante'];
        yield 'docente sobre un estudiante' => ['docente_director', 'estudiante'];
        yield 'administrador sobre un superadministrador' => ['administrador', 'superadministrador'];
        yield 'administrador sobre otro administrador' => ['administrador', 'administrador'];
    }

    #[DataProvider('sinPermiso')]
    public function testSinPermisoResponde403SinSubirNada(string $actor, string $objetivo): void
    {
        [, $otro] = $this->sesionComo($this->app, $objetivo);
        [$token] = $this->sesionComo($this->app, $actor);

        $this->assertError($this->subir((string) $otro, TestImages::subida(TestImages::png()), $token), 403, 'FORBIDDEN');
        self::assertSame([], $this->cloudinary->subidas);
        self::assertSame('ana', $this->db->usuarios[(string) $otro]['foto_perfil']['public_id']);
    }

    public function testSinTokenResponde401(): void
    {
        [, $id] = $this->sesionComo($this->app, 'estudiante');

        $this->assertError($this->subir((string) $id, TestImages::subida(TestImages::png())), 401, 'UNAUTHORIZED');
        self::assertSame([], $this->cloudinary->subidas);
    }

    public function testAdministradorRecibe404SiNoExiste(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->subir('650f1c2e8b3a4a0012345678', TestImages::subida(TestImages::png()), $token), 404, 'NOT_FOUND');
        self::assertSame([], $this->cloudinary->subidas);
    }

    public function testArchivoInvalido(): void
    {
        $app = $this->authApp(['PERFIL_FOTO_MAX_BYTES' => '1024']);
        [$token, $id] = $this->sesionComo($app, 'estudiante');

        $casos = [
            [TestImages::subida(TestImages::gif(), UPLOAD_ERR_OK, 'image/png'), 415, 'UNSUPPORTED_MEDIA_TYPE'],
            [TestImages::subida(TestImages::disfrazado()), 415, 'UNSUPPORTED_MEDIA_TYPE'],
            [TestImages::subida(TestImages::pngDeTamano(1025)), 413, 'FILE_TOO_LARGE'],
            [TestImages::subida(TestImages::png(), UPLOAD_ERR_INI_SIZE), 413, 'FILE_TOO_LARGE'],
        ];
        foreach ($casos as [$archivo, $status, $code]) {
            $this->assertError($app->handle($this->peticion((string) $id, $archivo, $token)), $status, $code);
        }

        $sinArchivo = $app->handle($this->request('POST', '/api/v1/users/' . $id . '/photo', null, self::bearer($token)));
        $this->assertError($sinArchivo, 400, 'VALIDATION_ERROR');
        self::assertSame(['foto'], array_keys($this->json($sinArchivo)['error']['fields']));

        self::assertSame([], $this->cloudinary->subidas);
        self::assertSame('ana', $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
    }

    public function testFalloDeCloudinaryResponde502YNoCambiaNada(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');
        $this->cloudinary->fallarSubida = true;

        $response = $this->subir((string) $id, TestImages::subida(TestImages::png()), $token);

        $this->assertError($response, 502, 'CLOUDINARY_UNAVAILABLE');
        self::assertStringNotContainsString('simulado', (string) $response->getBody());
        self::assertSame('ana', $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testImagenRechazadaPorCloudinaryResponde415YConservaLaAnterior(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');
        $this->cloudinary->rechazarEnSubida = 1;

        $response = $this->subir((string) $id, TestImages::subida(TestImages::png()), $token);

        $this->assertError($response, 415, 'UNSUPPORTED_MEDIA_TYPE');
        self::assertStringContainsString('dañada', $this->json($response)['error']['message']);
        self::assertSame('ana', $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testFalloDeMongoBorraLaFotoRecienSubida(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');
        $this->usuarios->fallarAlActualizar = true;

        $response = $this->subir((string) $id, TestImages::subida(TestImages::png()), $token);

        $this->assertError($response, 500, 'INTERNAL_ERROR');
        // Compensación: se borra la nueva y se conserva la anterior.
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borrados);
        self::assertSame('ana', $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
    }

    public function testFalloAlBorrarLaAnteriorNoRompeElCambio(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');
        $this->cloudinary->fallarBorrado = true;

        $response = $this->subir((string) $id, TestImages::subida(TestImages::png()), $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->cloudinary->subidas[0]['public_id'], $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
        $logs = implode('', array_map('file_get_contents', glob($this->storage . '/logs/*.log') ?: []));
        self::assertStringContainsString('"public_id":"ana"', $logs);
    }

    // --- DELETE /users/{id}/photo (DEC-B74) -------------------------------------------------------------------

    public function testQuitarLaFotoLaDejaEnNullYLaBorraDeCloudinary(): void
    {
        // crearCuenta deja una foto previa con public_id "ana".
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->quitar((string) $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNull($this->json($response)['usuario']['foto_perfil']);
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
        self::assertNull($this->db->usuarios[(string) $id]['foto_perfil']);
        self::assertSame(['ana'], $this->cloudinary->borrados);
        self::assertSame([], $this->cloudinary->subidas);

        // La vista pública también queda sin foto.
        $publico = $this->json($this->app->handle($this->request('GET', '/api/v1/users/' . $id)))['usuario'];
        self::assertNull($publico['foto_perfil']);
    }

    public function testQuitarSinFotoRespondeExitoSinHacerNada(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante', ['foto_perfil' => null]);

        $response = $this->quitar((string) $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->json($response)['usuario']['foto_perfil']);
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testQuitarSinSesionResponde401(): void
    {
        [, $id] = $this->sesionComo($this->app, 'estudiante');

        $this->assertError($this->quitar((string) $id), 401, 'UNAUTHORIZED');
        self::assertSame('ana', $this->db->usuarios[(string) $id]['foto_perfil']['public_id']);
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testNadieQuitaLaFotoDeOtroNiUnAdministrador(): void
    {
        [, $estudiante] = $this->sesionComo($this->app, 'estudiante');
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->quitar((string) $estudiante, $token), 403, 'FORBIDDEN');
        self::assertSame('ana', $this->db->usuarios[(string) $estudiante]['foto_perfil']['public_id']);
        self::assertSame([], $this->cloudinary->borrados);
    }

    public function testFalloDeCloudinaryAlQuitarQuedaEnElLogYLaFotoSeQuita(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');
        $this->cloudinary->fallarBorrado = true;

        $response = $this->quitar((string) $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->db->usuarios[(string) $id]['foto_perfil']);
        $logs = implode('', array_map('file_get_contents', glob($this->storage . '/logs/*.log') ?: []));
        self::assertStringContainsString('"public_id":"ana"', $logs);
    }

    private function quitar(string $id, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request('DELETE', '/api/v1/users/' . $id . '/photo', null, $token === null ? [] : self::bearer($token)));
    }

    private function subir(string $id, UploadedFile $archivo, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->peticion($id, $archivo, $token));
    }

    private function peticion(string $id, UploadedFile $archivo, ?string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->request('POST', '/api/v1/users/' . $id . '/photo', null, $token === null ? [] : self::bearer($token))
            ->withUploadedFiles(['foto' => $archivo]);
    }
}
