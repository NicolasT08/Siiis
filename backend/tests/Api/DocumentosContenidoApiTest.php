<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Services\CloudinaryService;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\TestImages;

/**
 * DEC-B70: el contenido de proyectos, artículos y noticias es texto Markdown, un PDF o ambos. Publicar o editar
 * no puede dejar un documento sin ninguno de los dos; el borrador puede nacer vacío porque el PDF se sube después
 * de crearlo.
 */
final class DocumentosContenidoApiTest extends DocumentosTestCase
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modulos(): iterable
    {
        yield 'proyecto' => ['projects', 'proyecto'];
        yield 'artículo' => ['articles', 'articulo'];
        yield 'noticia' => ['articles', 'noticia'];
    }

    // ---------------------------------------------------------------- los cuatro casos al publicar

    #[DataProvider('modulos')]
    public function testSoloTexto(string $modulo, string $categoria): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria, [
            'contenido' => "## Resumen\n\nTexto **en Markdown**.",
            'visible' => true,
        ]), $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $doc = $this->json($response)['documento'];
        self::assertSame("## Resumen\n\nTexto **en Markdown**.", $doc['contenido']);
        self::assertSame([], $doc['archivos_adjuntos']);
    }

    #[DataProvider('modulos')]
    public function testSoloPdf(string $modulo, string $categoria): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        // El borrador nace vacío, se le sube el PDF y después se publica.
        $creado = $this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria, ['contenido' => '   ']), $token);
        self::assertSame(201, $creado->getStatusCode(), (string) $creado->getBody());
        $id = $this->json($creado)['documento']['id'];
        self::assertNull($this->json($creado)['documento']['contenido']);

        self::assertSame(201, $this->subirPdf($modulo, $id, $token)->getStatusCode());
        $publicado = $this->send('PATCH', '/api/v1/' . $modulo . '/' . $id, ['estado' => self::estadoPublico($categoria), 'visible' => true], $token);

        self::assertSame(200, $publicado->getStatusCode(), (string) $publicado->getBody());
        $doc = $this->json($publicado)['documento'];
        self::assertNull($doc['contenido']);
        self::assertCount(1, $doc['archivos_adjuntos']);
        self::assertTrue($doc['visible']);
    }

    #[DataProvider('modulos')]
    public function testTextoYPdf(string $modulo, string $categoria): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $creado = $this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria, ['contenido' => 'Texto']), $token);
        $id = $this->json($creado)['documento']['id'];
        self::assertSame(201, $this->subirPdf($modulo, $id, $token)->getStatusCode());

        $publicado = $this->send('PATCH', '/api/v1/' . $modulo . '/' . $id, ['estado' => self::estadoPublico($categoria), 'visible' => true], $token);

        self::assertSame(200, $publicado->getStatusCode(), (string) $publicado->getBody());
        $doc = $this->json($publicado)['documento'];
        self::assertSame('Texto', $doc['contenido']);
        self::assertCount(1, $doc['archivos_adjuntos']);
    }

    #[DataProvider('modulos')]
    public function testNingunoNoSePublica(string $modulo, string $categoria): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        // Crear ya publicado o visible sin texto (el PDF todavía no puede venir).
        $this->assertSinContenido($this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria, ['contenido' => null, 'visible' => true]), $token));
        $this->assertSinContenido($this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria, ['estado' => self::estadoPublico($categoria)]), $token));
        self::assertSame([], $this->db->documentos, 'no se guardó nada');

        // Publicar un borrador vacío.
        $creado = $this->send('POST', '/api/v1/' . $modulo, $this->cuerpo($categoria), $token);
        self::assertSame(201, $creado->getStatusCode(), 'el borrador sí puede nacer vacío');
        $id = $this->json($creado)['documento']['id'];
        $this->assertSinContenido($this->send('PATCH', '/api/v1/' . $modulo . '/' . $id, ['visible' => true], $token));
        $this->assertSinContenido($this->send('PATCH', '/api/v1/' . $modulo . '/' . $id, ['estado' => self::estadoPublico($categoria)], $token));
        self::assertFalse($this->db->documentos[$id]['visible']);
        self::assertSame('en_revision', $this->db->documentos[$id]['estado']);
    }

    // ---------------------------------------------------------------- editar y quitar

    public function testQuitarElTextoSiConservaPdfYNoSiNoLoTiene(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $conPdf = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [self::pdf('siiis/documentos/a1')]]);
        $soloTexto = $this->documento(['autor_id' => $yo]);

        $ok = $this->send('PATCH', '/api/v1/projects/' . $conPdf, ['contenido' => ''], $token);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertNull($this->json($ok)['documento']['contenido']);

        $this->assertSinContenido($this->send('PATCH', '/api/v1/projects/' . $soloTexto, ['contenido' => null], $token));
        self::assertSame('Contenido', $this->db->documentos[(string) $soloTexto]['contenido']);
    }

    public function testQuitarElPdfSiConservaTextoYNoSiNoLoTiene(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $conTexto = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [self::pdf('siiis/documentos/b1')]]);
        $soloPdf = $this->documento(['autor_id' => $yo, 'contenido' => null, 'archivos_adjuntos' => [self::pdf('siiis/documentos/c1')]]);

        $ok = $this->send('DELETE', '/api/v1/projects/' . $conTexto . '/files/b1', null, $token);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame([], $this->json($ok)['documento']['archivos_adjuntos']);

        $this->assertSinContenido($this->send('DELETE', '/api/v1/projects/' . $soloPdf . '/files/c1', null, $token));
        self::assertCount(1, $this->db->documentos[(string) $soloPdf]['archivos_adjuntos']);
        self::assertSame(['siiis/documentos/b1'], $this->cloudinary->borrados, 'el PDF que se queda no se borra de Cloudinary');
    }

    public function testUnaImagenNoCuentaComoPdf(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'archivos_adjuntos' => [[
            'url' => 'https://res.cloudinary.com/demo/image/upload/v1/siiis/documentos/foto.jpg',
            'public_id' => 'siiis/documentos/foto',
            'nombre_archivo' => 'foto.jpg',
        ]]]);

        $this->assertSinContenido($this->send('PATCH', '/api/v1/projects/' . $id, ['contenido' => null], $token));
    }

    public function testDocumentosExistentesSiguenSiendoValidos(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        // Publicado antes de DEC-B70: solo PDF, o (dato antiguo) sin texto ni PDF.
        $soloPdf = $this->documento(['contenido' => null, 'estado' => 'ejecucion', 'visible' => true, 'archivos_adjuntos' => [self::pdf('siiis/documentos/d1')]]);
        $vacio = $this->documento(['contenido' => null, 'estado' => 'ejecucion', 'visible' => true]);

        self::assertSame(200, $this->get('/api/v1/projects/' . $soloPdf)->getStatusCode());
        self::assertSame(200, $this->get('/api/v1/projects/' . $vacio)->getStatusCode());
        // Editar lo que no es el contenido no exige migrarlos.
        self::assertSame(200, $this->send('PATCH', '/api/v1/projects/' . $soloPdf, ['titulo' => 'Otro'], $token)->getStatusCode());
        self::assertSame(200, $this->send('PATCH', '/api/v1/projects/' . $vacio, ['titulo' => 'Otro'], $token)->getStatusCode());
        self::assertSame(['ejecucion', 'ejecucion'], array_column($this->json($this->get('/api/v1/projects'))['data'], 'estado'));
    }

    public function testTextoDemasiadoLargo(): void
    {
        $this->app = $this->authApp(['DOCUMENTO_CONTENIDO_MAX_LENGTH' => '10']);
        [$token] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->send('POST', '/api/v1/projects', $this->cuerpo('proyecto', ['contenido' => str_repeat('a', 11)]), $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame('Máximo 10 caracteres.', $this->json($response)['error']['fields']['contenido']);
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function cuerpo(string $categoria, array $extra = []): array
    {
        $cuerpo = ['titulo' => 'Documento', 'descripcion' => 'Resumen'];
        if ($categoria !== 'proyecto') {
            $cuerpo['categoria'] = $categoria;
        }

        return $extra + $cuerpo;
    }

    private static function estadoPublico(string $categoria): string
    {
        return $categoria === 'proyecto' ? 'ejecucion' : 'publicado';
    }

    private function subirPdf(string $modulo, string $id, string $token): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/' . $modulo . '/' . $id . '/files', null, self::bearer($token))
                ->withUploadedFiles(['archivo' => TestImages::subida(TestImages::pdf(), UPLOAD_ERR_OK, 'application/pdf', 'informe.pdf')]),
        );
    }

    /**
     * @return array{url: string, public_id: string, nombre_archivo: string}
     */
    private static function pdf(string $publicId): array
    {
        return [
            'url' => 'https://res.cloudinary.com/demo/image/upload/v1/' . $publicId . '.pdf',
            'public_id' => $publicId,
            'nombre_archivo' => 'documento.pdf',
        ];
    }

    private function assertSinContenido(ResponseInterface $response): void
    {
        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame(
            'Escribe el contenido o sube un PDF: el documento necesita al menos uno de los dos.',
            $this->json($response)['error']['fields']['contenido'],
        );
    }
}
