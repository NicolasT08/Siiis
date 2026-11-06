<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Services\CloudinaryService;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\TestImages;
use Slim\Psr7\UploadedFile;

/**
 * Adjuntos de documentos (DEC-B39, DEC-B40) con Cloudinary simulado:
 * POST /{projects|articles}/{id}/files y DELETE /{projects|articles}/{id}/files/{archivo}.
 */
final class DocumentosAdjuntosApiTest extends DocumentosTestCase
{
    private FakeCloudinaryService $cloudinary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudinary = new FakeCloudinaryService();
        $this->extraOverrides = [CloudinaryService::class => $this->cloudinary];
        $this->app = $this->authApp();
    }

    protected function tearDown(): void
    {
        TestImages::limpiar();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- subir

    public function testAutorSubeUnPdfMientrasEstaEnRevision(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $this->clock->advance(60);

        $response = $this->subir('projects', $id, TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf', 'Informe final.PDF'), $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertCount(1, $this->cloudinary->subidas);
        $subida = $this->cloudinary->subidas[0];
        self::assertSame('siiis/documentos', $subida['folder']);
        // Todo como recurso image, con el PDF en la lista de formatos permitidos (DEC-B40).
        self::assertSame(['jpg', 'png', 'webp', 'pdf'], $subida['formatos']);
        // El PDF se guarda tal cual y se entrega sin f_auto,q_auto (DEC-B42).
        self::assertSame([], $subida['transformacion']);

        $doc = $this->json($response)['documento'];
        $archivo = substr($subida['public_id'], strlen('siiis/documentos/'));
        $url = 'https://res.cloudinary.com/demo/image/upload/' . $subida['public_id'] . '.pdf';
        self::assertSame([[
            'archivo' => $archivo,
            'url' => $url,
            'nombre_archivo' => 'Informe final.pdf',
        ]], $doc['archivos_adjuntos']);
        self::assertSame('2026-09-25T12:01:00Z', $doc['fecha_actualizacion']);
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
        self::assertSame(
            [['url' => $url, 'public_id' => $subida['public_id'], 'nombre_archivo' => 'Informe final.pdf']],
            $this->db->documentos[(string) $id]['archivos_adjuntos'],
        );

        // Quitarlo lo borra de Cloudinary.
        self::assertSame(200, $this->send('DELETE', '/api/v1/projects/' . $id . '/files/' . $archivo, null, $token)->getStatusCode());
        self::assertSame([$subida['public_id']], $this->cloudinary->borrados);
    }

    public function testLaImagenSeReduceAlSubirYSeEntregaOptimizada(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        $response = $this->subir('projects', $id, TestImages::subida(TestImages::png(), UPLOAD_ERR_OK, 'image/png', 'foto.png'), $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $subida = $this->cloudinary->subidas[0];
        self::assertSame(['width' => 1600, 'crop' => 'limit', 'quality' => 'auto'], $subida['transformacion']);
        self::assertSame(
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/' . $subida['public_id'] . '.png',
            $this->json($response)['documento']['archivos_adjuntos'][0]['url'],
        );
        // MongoDB guarda la URL original de Cloudinary.
        self::assertSame(
            'https://res.cloudinary.com/demo/image/upload/' . $subida['public_id'] . '.png',
            $this->db->documentos[(string) $id]['archivos_adjuntos'][0]['url'],
        );
    }

    public function testTransformacionYLimitesConfigurables(): void
    {
        $app = $this->authApp([
            'DOCUMENTO_IMAGEN_MAX_ANCHO_PX' => '800',
            'CLOUDINARY_CALIDAD' => '70',
            'DOCUMENTO_PDF_MAX_BYTES' => '2048',
        ]);
        [$token, $yo] = $this->sesionComo($app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $subir = fn (UploadedFile $archivo): ResponseInterface => $app->handle(
            $this->request('POST', '/api/v1/projects/' . $id . '/files', null, self::bearer($token))
                ->withUploadedFiles(['archivo' => $archivo]),
        );

        self::assertSame(201, $subir(TestImages::subida(TestImages::webp()))->getStatusCode());
        self::assertSame(['width' => 800, 'crop' => 'limit', 'quality' => '70'], $this->cloudinary->subidas[0]['transformacion']);

        $this->assertError($subir(TestImages::subida(TestImages::pdfDeTamano(2049))), 413, 'FILE_TOO_LARGE');
        self::assertCount(1, $this->cloudinary->subidas);
    }

    /**
     * Por defecto (DEC-B42): PDF hasta 3 MB e imágenes hasta 5 MB.
     */
    public function testLimitesPorDefectoDePdfEImagen(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        self::assertSame(201, $this->subir('projects', $id, TestImages::subida(TestImages::pdfDeTamano(3145728)), $token)->getStatusCode());
        $muyGrande = $this->subir('projects', $id, TestImages::subida(TestImages::pdfDeTamano(3145729)), $token);
        $this->assertError($muyGrande, 413, 'FILE_TOO_LARGE');
        self::assertSame('El PDF supera el tamaño máximo de 3 MB.', $this->json($muyGrande)['error']['message']);
        // Una imagen de 4 MB sí entra: se reduce al subir.
        self::assertSame(201, $this->subir('projects', $id, TestImages::subida(TestImages::pngDeTamano(4194304)), $token)->getStatusCode());
        self::assertCount(2, $this->cloudinary->subidas);
    }

    public function testFuncionaTambienEnArticles(): void
    {
        [$token] = $this->sesionComo($this->app, 'docente_director');
        $id = $this->documento(['categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true]);

        $response = $this->subir('articles', $id, TestImages::subida(TestImages::jpeg()), $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->json($this->get('/api/v1/articles/' . $id))['documento']['archivos_adjuntos']);
        // Un proyecto no se toca desde /articles ni al revés.
        $this->assertError($this->subir('projects', $id, TestImages::subida(TestImages::png()), $token), 404, 'NOT_FOUND');
        self::assertCount(1, $this->cloudinary->subidas);
    }

    public function testCarpetaConfigurable(): void
    {
        $app = $this->authApp(['CLOUDINARY_DOCUMENTOS_FOLDER' => '/siiis_test/documentos/']);
        [$token, $yo] = $this->sesionComo($app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        $request = $this->request('POST', '/api/v1/projects/' . $id . '/files', null, self::bearer($token))
            ->withUploadedFiles(['archivo' => TestImages::subida(TestImages::png())]);

        self::assertSame(201, $app->handle($request)->getStatusCode());
        self::assertSame('siiis_test/documentos', $this->cloudinary->subidas[0]['folder']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, int, string}>
     */
    public static function sinPermiso(): iterable
    {
        yield 'autor de un documento publicado' => ['autor', ['estado' => 'ejecucion', 'visible' => true], 403, 'FORBIDDEN'];
        yield 'otro integrante sobre uno público' => ['otro', ['estado' => 'ejecucion', 'visible' => true], 403, 'FORBIDDEN'];
        yield 'integrante del documento' => ['integrante', [], 403, 'FORBIDDEN'];
        yield 'otro integrante sobre un borrador ajeno' => ['otro', [], 404, 'NOT_FOUND'];
    }

    /**
     * @param array<string, mixed> $campos
     */
    #[DataProvider('sinPermiso')]
    public function testSinPermisoNoSubeNada(string $actor, array $campos, int $status, string $code): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $autor = $actor === 'autor' ? $yo : $this->usuario('estudiante');
        $integrantes = $actor === 'integrante' ? [$yo] : [];
        $id = $this->documento($campos + ['autor_id' => $autor, 'integrantes' => $integrantes]);

        $this->assertError($this->subir('projects', $id, TestImages::subida(TestImages::pdf()), $token), $status, $code);
        self::assertSame([], $this->cloudinary->subidas);
        self::assertSame([], $this->db->documentos[(string) $id]['archivos_adjuntos']);
    }

    public function testSinSesion401(): void
    {
        $id = $this->documento(['estado' => 'ejecucion', 'visible' => true]);

        $this->assertError($this->subir('projects', $id, TestImages::subida(TestImages::pdf())), 401, 'UNAUTHORIZED');
        self::assertSame([], $this->cloudinary->subidas);
    }

    /**
     * @return iterable<string, array{callable(): UploadedFile, int, string}>
     */
    public static function archivosInvalidos(): iterable
    {
        yield 'gif' => [static fn (): UploadedFile => TestImages::subida(TestImages::gif()), 415, 'UNSUPPORTED_MEDIA_TYPE'];
        yield 'script disfrazado de pdf' => [
            static fn (): UploadedFile => TestImages::subida(TestImages::disfrazado(), UPLOAD_ERR_OK, 'application/pdf', 'x.pdf'),
            415,
            'UNSUPPORTED_MEDIA_TYPE',
        ];
        yield 'demasiado grande' => [static fn (): UploadedFile => TestImages::subida(TestImages::pngDeTamano(5242881)), 413, 'FILE_TOO_LARGE'];
        yield 'límite de PHP' => [static fn (): UploadedFile => TestImages::subida(TestImages::png(), UPLOAD_ERR_INI_SIZE), 413, 'FILE_TOO_LARGE'];
        yield 'vacío' => [static fn (): UploadedFile => TestImages::subida(TestImages::vacio()), 400, 'VALIDATION_ERROR'];
    }

    /**
     * @param callable(): UploadedFile $archivo
     */
    #[DataProvider('archivosInvalidos')]
    public function testArchivoInvalido(callable $archivo, int $status, string $code): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        $this->assertError($this->subir('projects', $id, $archivo(), $token), $status, $code);
        self::assertSame([], $this->cloudinary->subidas);
    }

    public function testSinArchivoOCampoEquivocado400(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $base = $this->request('POST', '/api/v1/projects/' . $id . '/files', null, self::bearer($token));

        $sin = $this->app->handle($base);
        $this->assertError($sin, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('archivo', $this->json($sin)['error']['fields']);
        $this->assertError($this->app->handle($base->withUploadedFiles(['foto' => TestImages::subida(TestImages::pdf())])), 400, 'VALIDATION_ERROR');
        $this->assertError($this->app->handle($base->withUploadedFiles(['archivo' => [TestImages::subida(TestImages::pdf())]])), 400, 'VALIDATION_ERROR');
    }

    public function testLimiteDeAdjuntos(): void
    {
        $app = $this->authApp(['DOCUMENTO_ADJUNTOS_MAX' => '2']);
        [$token, $yo] = $this->sesionComo($app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $subir = fn (): ResponseInterface => $app->handle(
            $this->request('POST', '/api/v1/projects/' . $id . '/files', null, self::bearer($token))
                ->withUploadedFiles(['archivo' => TestImages::subida(TestImages::pdf())]),
        );

        self::assertSame(201, $subir()->getStatusCode());
        self::assertSame(201, $subir()->getStatusCode());
        $response = $subir();

        $this->assertError($response, 409, 'ATTACHMENT_LIMIT');
        self::assertSame('El documento ya tiene el máximo de 2 archivos adjuntos.', $this->json($response)['error']['message']);
        self::assertCount(2, $this->cloudinary->subidas, 'sin cupo no se sube nada');
        self::assertCount(2, $this->db->documentos[(string) $id]['archivos_adjuntos']);
    }

    public function testFalloDeCloudinary502SinCambios(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $this->cloudinary->fallarSubida = true;

        $response = $this->subir('projects', $id, TestImages::subida(TestImages::pdf()), $token);

        $this->assertError($response, 502, 'CLOUDINARY_UNAVAILABLE');
        self::assertStringNotContainsString('simulado', (string) $response->getBody());
        self::assertSame([], $this->db->documentos[(string) $id]['archivos_adjuntos']);
    }

    public function testFalloDeMongoBorraLoSubido(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        $this->documentos->fallarAlActualizar = true;

        $response = $this->subir('projects', $id, TestImages::subida(TestImages::pdf()), $token);

        $this->assertError($response, 500, 'INTERNAL_ERROR');
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borrados);
    }

    public function testPublicacionSimultaneaBorraLoSubido(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);
        // Un revisor publica mientras el autor sube el archivo.
        $this->documentos->alLeer = function () use ($id): void {
            $this->db->documentos[(string) $id]['estado'] = 'ejecucion';
        };

        $this->assertError($this->subir('projects', $id, TestImages::subida(TestImages::pdf()), $token), 403, 'FORBIDDEN');
        self::assertSame([$this->cloudinary->subidas[0]['public_id']], $this->cloudinary->borrados);
        self::assertSame([], $this->db->documentos[(string) $id]['archivos_adjuntos']);
    }

    // ---------------------------------------------------------------- quitar

    public function testQuitarAdjunto(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [
            self::adjunto('siiis/documentos/aaa111'),
            self::adjunto('siiis/documentos/bbb222'),
        ]]);

        $response = $this->send('DELETE', '/api/v1/projects/' . $id . '/files/aaa111', null, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['bbb222'], array_column($this->json($response)['documento']['archivos_adjuntos'], 'archivo'));
        self::assertSame(['siiis/documentos/aaa111'], $this->cloudinary->borrados);
        self::assertSame(['siiis/documentos/bbb222'], array_column($this->db->documentos[(string) $id]['archivos_adjuntos'], 'public_id'));

        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $id . '/files/aaa111', null, $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $id . '/files/siiis%2Fdocumentos%2Fbbb222', null, $token), 404, 'NOT_FOUND');
    }

    public function testQuitarRespetaLosPermisos(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        [$tokenRevisor] = $this->sesionComo($this->app, 'administrador');
        $publicado = $this->documento(['autor_id' => $yo, 'estado' => 'finalizado', 'visible' => true, 'archivos_adjuntos' => [self::adjunto('siiis/documentos/ccc333')]]);

        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $publicado . '/files/ccc333'), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $publicado . '/files/ccc333', null, $token), 403, 'FORBIDDEN');
        self::assertSame([], $this->cloudinary->borrados);

        self::assertSame(200, $this->send('DELETE', '/api/v1/projects/' . $publicado . '/files/ccc333', null, $tokenRevisor)->getStatusCode());
        self::assertSame(['siiis/documentos/ccc333'], $this->cloudinary->borrados);
    }

    public function testFalloAlBorrarEnCloudinaryNoRompeLaOperacion(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [self::adjunto('siiis/documentos/ddd444')]]);
        $this->cloudinary->fallarBorrado = true;

        $response = $this->send('DELETE', '/api/v1/projects/' . $id . '/files/ddd444', null, $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->db->documentos[(string) $id]['archivos_adjuntos']);
        self::assertStringContainsString('"public_id":"siiis/documentos/ddd444"', $this->logs());
    }

    // ---------------------------------------------------------------- borrar el documento

    public function testBorrarElDocumentoBorraSusAdjuntos(): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');
        $id = $this->documento(['estado' => 'ejecucion', 'visible' => true, 'archivos_adjuntos' => [
            self::adjunto('siiis/documentos/eee555'),
            self::adjunto('siiis/documentos/fff666'),
        ]]);

        self::assertSame(204, $this->send('DELETE', '/api/v1/projects/' . $id, null, $token)->getStatusCode());
        self::assertSame(['siiis/documentos/eee555', 'siiis/documentos/fff666'], $this->cloudinary->borrados);
        self::assertSame([], $this->db->documentos);
    }

    public function testSiCloudinaryFallaElDocumentoIgualSeBorra(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [self::adjunto('siiis/documentos/ggg777')]]);
        $this->cloudinary->fallarBorrado = true;

        self::assertSame(204, $this->send('DELETE', '/api/v1/projects/' . $id, null, $token)->getStatusCode());
        self::assertSame([], $this->db->documentos);
        self::assertStringContainsString('ggg777', $this->logs());
    }

    public function testNoSeBorraNadaSiElBorradoDelDocumentoNoProcede(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'estado' => 'ejecucion', 'visible' => true, 'archivos_adjuntos' => [self::adjunto('siiis/documentos/hhh888')]]);

        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $id, null, $token), 403, 'FORBIDDEN');
        self::assertSame([], $this->cloudinary->borrados);
    }

    // ---------------------------------------------------------------- ayudas

    private function subir(string $modulo, ObjectId $id, UploadedFile $archivo, ?string $token = null): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/' . $modulo . '/' . $id . '/files', null, $token === null ? [] : self::bearer($token))
                ->withUploadedFiles(['archivo' => $archivo]),
        );
    }

    /**
     * @return array{url: string, public_id: string, nombre_archivo: string}
     */
    private static function adjunto(string $publicId): array
    {
        return [
            'url' => 'https://res.cloudinary.com/demo/image/upload/v1/' . $publicId . '.pdf',
            'public_id' => $publicId,
            'nombre_archivo' => 'archivo.pdf',
        ];
    }

    private function logs(): string
    {
        return implode('', array_map('file_get_contents', glob($this->storage . '/logs/*.log') ?: []));
    }
}
