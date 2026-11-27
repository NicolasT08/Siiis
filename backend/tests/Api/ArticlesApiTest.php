<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /articles: artículos y noticias (DEC-B30, DEC-B31). Las reglas comunes de permisos, visibilidad y
 * validación se prueban a fondo en ProjectsApiTest; aquí se prueba lo propio del módulo y que esas reglas
 * también se aplican a esta ruta.
 */
final class ArticlesApiTest extends DocumentosTestCase
{
    protected const CATEGORIA = 'articulo';

    // ---------------------------------------------------------------- listado

    public function testListadoPublicoConArticulosYNoticias(): void
    {
        $autor = $this->usuario('estudiante');
        $articulo = $this->documento(['autor_id' => $autor, 'estado' => 'publicado', 'visible' => true]);
        $noticia = $this->documento(['autor_id' => $autor, 'categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true]);
        $this->documento(['autor_id' => $autor, 'categoria' => 'noticia', 'estado' => 'en_revision', 'visible' => true]);
        $this->documento(['autor_id' => $autor, 'estado' => 'publicado', 'visible' => false]);
        $this->documento(['autor_id' => $autor, 'categoria' => 'proyecto', 'estado' => 'ejecucion', 'visible' => true]);

        $response = $this->get('/api/v1/articles');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame([(string) $noticia, (string) $articulo], array_column($body['data'], 'id'));
        self::assertSame(['noticia', 'articulo'], array_column($body['data'], 'categoria'));
        self::assertSame(2, $body['meta']['total']);
        self::assertArrayNotHasKey('contenido', $body['data'][0]);
    }

    public function testFiltroPorCategoria(): void
    {
        $autor = $this->usuario('estudiante');
        $articulo = $this->documento(['autor_id' => $autor, 'estado' => 'publicado', 'visible' => true]);
        $noticia = $this->documento(['autor_id' => $autor, 'categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true]);

        self::assertSame([(string) $articulo], $this->ids('/api/v1/articles?categoria=articulo'));
        self::assertSame([(string) $noticia], $this->ids('/api/v1/articles?categoria=noticia&estado=publicado'));
        self::assertSame([(string) $noticia, (string) $articulo], $this->ids('/api/v1/articles?categoria='), 'vacío = sin filtro');
        self::assertSame([(string) $articulo], $this->ids('/api/v1/articles?categoria=articulo&autor=' . $autor));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function queriesInvalidas(): iterable
    {
        yield 'categoria proyecto' => ['categoria=proyecto', ['categoria']];
        yield 'categoria inexistente' => ['categoria=blog', ['categoria']];
        yield 'inyección en categoria' => ['categoria[$in][]=proyecto', ['categoria']];
        yield 'estado de proyecto' => ['estado=ejecucion', ['estado']];
        yield 'estado de proyecto con categoría' => ['categoria=noticia&estado=finalizado', ['estado']];
        yield 'varios errores' => ['categoria=x&integrante=1', ['categoria', 'integrante']];
    }

    /**
     * @param list<string> $campos
     */
    #[DataProvider('queriesInvalidas')]
    public function testQueryInvalida400(string $query, array $campos): void
    {
        $response = $this->get('/api/v1/articles?' . $query);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertEqualsCanonicalizing($campos, array_keys($this->json($response)['error']['fields']));
    }

    public function testVisibilidadPorActor(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        [$tokenRevisor] = $this->sesionComo($this->app, 'docente_director');
        $otro = $this->usuario('estudiante');
        $publico = $this->documento(['autor_id' => $otro, 'estado' => 'publicado', 'visible' => true]);
        $miBorrador = $this->documento(['autor_id' => $yo, 'categoria' => 'noticia', 'estado' => 'en_revision', 'visible' => false]);
        $ajeno = $this->documento(['autor_id' => $otro, 'estado' => 'en_revision', 'visible' => false]);

        self::assertSame([(string) $publico], $this->ids('/api/v1/articles'));
        self::assertSame([(string) $miBorrador, (string) $publico], $this->ids('/api/v1/articles', $token));
        self::assertSame([(string) $ajeno, (string) $miBorrador, (string) $publico], $this->ids('/api/v1/articles', $tokenRevisor));
        self::assertSame([(string) $ajeno, (string) $miBorrador], $this->ids('/api/v1/articles?visible=false', $tokenRevisor));
        $this->assertError($this->get('/api/v1/articles/' . $ajeno, $token), 404, 'NOT_FOUND');
    }

    // ---------------------------------------------------------------- detalle

    public function testDetalleYCruceDeModulos(): void
    {
        $autor = $this->usuario('estudiante');
        $noticia = $this->documento(['autor_id' => $autor, 'categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true, 'contenido' => '**Hoy**']);
        $proyecto = $this->documento(['autor_id' => $autor, 'categoria' => 'proyecto', 'estado' => 'ejecucion', 'visible' => true]);

        $doc = $this->json($this->get('/api/v1/articles/' . $noticia))['documento'];
        self::assertSame('noticia', $doc['categoria']);
        self::assertSame('**Hoy**', $doc['contenido']);

        $response = $this->get('/api/v1/articles/' . $proyecto);
        $this->assertError($response, 404, 'NOT_FOUND');
        self::assertSame('El artículo o noticia no existe.', $this->json($response)['error']['message']);
        $this->assertError($this->get('/api/v1/projects/' . $noticia), 404, 'NOT_FOUND');
    }

    // ---------------------------------------------------------------- crear

    public function testCrearArticuloYNoticia(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');

        foreach (['articulo', 'noticia'] as $categoria) {
            $response = $this->send('POST', '/api/v1/articles', $this->datos(['categoria' => $categoria]), $token);

            self::assertSame(201, $response->getStatusCode());
            $doc = $this->json($response)['documento'];
            self::assertSame($categoria, $doc['categoria']);
            self::assertSame('en_revision', $doc['estado']);
            self::assertFalse($doc['visible']);
            self::assertSame((string) $yo, $doc['autor']['id']);
        }
        self::assertCount(2, $this->db->documentos);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function datosInvalidos(): iterable
    {
        yield 'sin categoría' => [['categoria' => null], ['categoria']];
        yield 'categoría proyecto' => [['categoria' => 'proyecto'], ['categoria']];
        yield 'categoría con mayúsculas' => [['categoria' => 'Articulo'], ['categoria']];
        yield 'inyección en categoría' => [['categoria' => ['$ne' => '']], ['categoria']];
        yield 'estado de proyecto' => [['estado' => 'ejecucion'], ['estado']];
        yield 'categoría y estado inválidos' => [['categoria' => 'blog', 'estado' => 'publicado', 'titulo' => ''], ['categoria', 'titulo']];
    }

    /**
     * @param array<string, mixed> $cambios
     * @param list<string>         $campos
     */
    #[DataProvider('datosInvalidos')]
    public function testValidaciones(array $cambios, array $campos): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');
        $datos = $this->datos($cambios);
        if (array_key_exists('categoria', $cambios) && $cambios['categoria'] === null) {
            unset($datos['categoria']);
        }

        $response = $this->send('POST', '/api/v1/articles', $datos, $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertEqualsCanonicalizing($campos, array_keys($this->json($response)['error']['fields']));
        self::assertSame([], $this->db->documentos);
    }

    public function testIntegranteNoPublicaYRevisorSi(): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante_lider');

        $this->assertError(
            $this->send('POST', '/api/v1/articles', $this->datos(['estado' => 'publicado']), $token),
            403,
            'FORBIDDEN',
        );

        foreach (self::revisores() as [$rol]) {
            [$tokenRevisor] = $this->sesionComo($this->app, $rol);
            $response = $this->send('POST', '/api/v1/articles', $this->datos(['estado' => 'publicado', 'visible' => true]), $tokenRevisor);
            self::assertSame(201, $response->getStatusCode(), $rol);
        }
        self::assertSame(3, $this->json($this->get('/api/v1/articles'))['meta']['total']);
    }

    // ---------------------------------------------------------------- editar y borrar

    public function testAutorCambiaLaCategoriaMientrasEstaEnRevision(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        $response = $this->send('PATCH', '/api/v1/articles/' . $id, ['categoria' => 'noticia'], $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noticia', $this->json($response)['documento']['categoria']);
        $this->assertError($this->send('PATCH', '/api/v1/articles/' . $id, ['categoria' => 'proyecto'], $token), 400, 'VALIDATION_ERROR');
        self::assertSame('noticia', $this->db->documentos[(string) $id]['categoria']);
    }

    public function testRevisorPublicaYDespublica(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->documento(['autor_id' => $this->usuario('estudiante'), 'categoria' => 'noticia']);

        $publicada = $this->send('PATCH', '/api/v1/articles/' . $id, ['estado' => 'publicado', 'visible' => true], $token);
        self::assertSame(200, $publicada->getStatusCode());
        self::assertSame([(string) $id], $this->ids('/api/v1/articles?categoria=noticia'));

        $this->send('PATCH', '/api/v1/articles/' . $id, ['estado' => 'en_revision'], $token);
        self::assertSame([], $this->ids('/api/v1/articles'));

        $this->assertError($this->send('PATCH', '/api/v1/articles/' . $id, ['estado' => 'finalizado'], $token), 400, 'VALIDATION_ERROR');
    }

    public function testEdicionSinCamposMencionaLaCategoria(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo]);

        $response = $this->send('PATCH', '/api/v1/articles/' . $id, ['autor_id' => 'x'], $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertStringContainsString('visible, categoria', $this->json($response)['error']['message']);
    }

    public function testAutorNoEditaLoPublicado(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'estado' => 'publicado', 'visible' => true]);

        $this->assertError($this->send('PATCH', '/api/v1/articles/' . $id, ['titulo' => 'x'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('DELETE', '/api/v1/articles/' . $id, null, $token), 403, 'FORBIDDEN');
    }

    public function testBorrado(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        [$tokenRevisor] = $this->sesionComo($this->app, 'docente_director');
        $borrador = $this->documento(['autor_id' => $yo, 'categoria' => 'noticia']);
        $publicado = $this->documento(['autor_id' => $this->usuario('estudiante'), 'estado' => 'publicado', 'visible' => true]);
        $proyecto = $this->documento(['autor_id' => $yo, 'categoria' => 'proyecto']);

        $this->assertError($this->send('DELETE', '/api/v1/articles/' . $borrador), 401, 'UNAUTHORIZED');
        self::assertSame(204, $this->send('DELETE', '/api/v1/articles/' . $borrador, null, $token)->getStatusCode());
        self::assertSame(204, $this->send('DELETE', '/api/v1/articles/' . $publicado, null, $tokenRevisor)->getStatusCode());
        $this->assertError($this->send('DELETE', '/api/v1/articles/' . $proyecto, null, $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('DELETE', '/api/v1/articles/' . new ObjectId(), null, $tokenRevisor), 404, 'NOT_FOUND');
        self::assertSame([(string) $proyecto], array_keys($this->db->documentos));
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'categoria' => 'articulo',
            'titulo' => 'Artículo nuevo',
            'descripcion' => 'Resumen del artículo',
            'contenido' => "# Introducción\n\nTexto en **Markdown**.",
        ];
    }
}
