<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /projects (spec 10.9 y 11.3; DEC-B30 a DEC-B41) con repositorios en memoria.
 */
final class ProjectsApiTest extends DocumentosTestCase
{
    // ---------------------------------------------------------------- listado

    public function testAnonimoSoloVeProyectosPublicos(): void
    {
        $autor = $this->usuario('estudiante');
        $publico = $this->documento(['autor_id' => $autor, 'estado' => 'ejecucion', 'visible' => true]);
        $finalizado = $this->documento(['autor_id' => $autor, 'estado' => 'finalizado', 'visible' => true]);
        $this->documento(['autor_id' => $autor, 'estado' => 'en_revision', 'visible' => true]);
        $this->documento(['autor_id' => $autor, 'estado' => 'ejecucion', 'visible' => false]);
        $this->documento(['autor_id' => $autor, 'categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true]);

        $response = $this->get('/api/v1/projects');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Authorization', $response->getHeaderLine('Vary'));
        $body = $this->json($response);
        // Más recientes primero.
        self::assertSame([(string) $finalizado, (string) $publico], array_column($body['data'], 'id'));
        self::assertSame(['page' => 1, 'per_page' => 12, 'total' => 2, 'total_pages' => 1], $body['meta']);
        self::assertArrayNotHasKey('contenido', $body['data'][0]);
        self::assertArrayNotHasKey('archivos_adjuntos', $body['data'][0]);
        self::assertSame('proyecto', $body['data'][0]['categoria']);
        self::assertSame((string) $autor, $body['data'][0]['autor']['id']);
        // Con f_auto,q_auto (DEC-B42).
        self::assertSame(['url' => 'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/ana.jpg'], $body['data'][0]['autor']['foto_perfil']);
    }

    public function testResumenConAutorEIntegrantesSinDatosPrivados(): void
    {
        $autor = $this->usuario('estudiante', ['telefono' => '3001234567', 'biografia' => 'privada no']);
        $inactivo = $this->usuario('estudiante', ['estado' => false, 'nombre' => 'Egresado']);
        $this->documento(['autor_id' => $autor, 'integrantes' => [$inactivo, new ObjectId()], 'estado' => 'ejecucion', 'visible' => true]);

        $item = $this->json($this->get('/api/v1/projects'))['data'][0];

        self::assertSame(['id', 'nombre', 'apellido', 'rol', 'foto_perfil'], array_keys($item['autor']));
        // El integrante inactivo sigue apareciendo (DEC-B38); el que no existe se omite.
        self::assertSame(['Egresado'], array_column($item['integrantes'], 'nombre'));
    }

    public function testFiltrosDelListado(): void
    {
        $autor = $this->usuario('estudiante');
        $otro = $this->usuario('estudiante');
        $a = $this->documento(['autor_id' => $autor, 'estado' => 'ejecucion', 'visible' => true, 'integrantes' => [$otro]]);
        $b = $this->documento(['autor_id' => $otro, 'estado' => 'finalizado', 'visible' => true]);

        $ids = fn (string $query): array => array_column($this->json($this->get('/api/v1/projects?' . $query))['data'], 'id');

        self::assertSame([(string) $a], $ids('estado=ejecucion'));
        self::assertSame([(string) $b], $ids('estado=finalizado'));
        self::assertSame([], $ids('estado=en_revision'), 'el público nunca ve borradores');
        self::assertSame([(string) $a], $ids('autor=' . $autor));
        self::assertSame([(string) $a], $ids('integrante=' . $otro . '&categoria=articulo'), 'categoria se ignora en /projects');
        self::assertSame([(string) $a], $ids('integrante=' . $otro . '&autor=' . strtoupper((string) $autor)));
        self::assertSame([(string) $b, (string) $a], $ids('visible=false'), 'visible solo filtra para revisores');
    }

    public function testPaginacion(): void
    {
        $autor = $this->usuario('estudiante');
        for ($i = 0; $i < 5; ++$i) {
            $this->documento(['autor_id' => $autor, 'estado' => 'ejecucion', 'visible' => true]);
        }

        $pagina = $this->json($this->get('/api/v1/projects?page=3&per_page=2'));
        self::assertCount(1, $pagina['data']);
        self::assertSame(['page' => 3, 'per_page' => 2, 'total' => 5, 'total_pages' => 3], $pagina['meta']);
        self::assertSame([], $this->json($this->get('/api/v1/projects?page=4&per_page=2'))['data']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function queriesInvalidas(): iterable
    {
        yield 'estado de artículo' => ['estado=publicado', ['estado']];
        yield 'estado inexistente' => ['estado=borrador', ['estado']];
        yield 'inyección en estado' => ['estado[$ne]=en_revision', ['estado']];
        yield 'autor no es id' => ['autor=abc', ['autor']];
        yield 'integrante arreglo' => ['integrante[]=x', ['integrante']];
        yield 'página 0' => ['page=0', ['page']];
        yield 'per_page sobre el máximo' => ['per_page=51', ['per_page']];
    }

    /**
     * @param list<string> $campos
     */
    #[DataProvider('queriesInvalidas')]
    public function testQueryInvalida400(string $query, array $campos): void
    {
        $response = $this->get('/api/v1/projects?' . $query);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($campos, array_keys($this->json($response)['error']['fields']));
    }

    public function testIntegranteVeLoPublicoYLoSuyo(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $otro = $this->usuario('estudiante');
        $publico = $this->documento(['autor_id' => $otro, 'estado' => 'ejecucion', 'visible' => true]);
        $miBorrador = $this->documento(['autor_id' => $yo, 'estado' => 'en_revision', 'visible' => false]);
        $comoIntegrante = $this->documento(['autor_id' => $otro, 'integrantes' => [$yo], 'estado' => 'en_revision', 'visible' => false]);
        $this->documento(['autor_id' => $otro, 'estado' => 'en_revision', 'visible' => false]);
        $this->documento(['autor_id' => $otro, 'estado' => 'finalizado', 'visible' => false]);

        $response = $this->get('/api/v1/projects', $token);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertEqualsCanonicalizing(
            [(string) $publico, (string) $miBorrador, (string) $comoIntegrante],
            array_column($this->json($response)['data'], 'id'),
        );
        self::assertSame(
            [(string) $comoIntegrante, (string) $miBorrador],
            array_column($this->json($this->get('/api/v1/projects?estado=en_revision', $token))['data'], 'id'),
        );
    }

    #[DataProvider('revisores')]
    public function testRevisorVeTodoYFiltraPorVisible(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $otro = $this->usuario('estudiante');
        $visible = $this->documento(['autor_id' => $otro, 'estado' => 'ejecucion', 'visible' => true]);
        $oculto = $this->documento(['autor_id' => $otro, 'estado' => 'finalizado', 'visible' => false]);
        $borrador = $this->documento(['autor_id' => $otro, 'estado' => 'en_revision', 'visible' => false]);

        $ids = fn (string $q): array => array_column($this->json($this->get('/api/v1/projects' . $q, $token))['data'], 'id');

        self::assertEqualsCanonicalizing([(string) $visible, (string) $oculto, (string) $borrador], $ids(''));
        self::assertEqualsCanonicalizing([(string) $oculto, (string) $borrador], $ids('?visible=false'));
        self::assertSame([(string) $borrador], $ids('?estado=en_revision'));
    }

    public function testListadoSinNMasUno(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->documento([
                'autor_id' => $this->usuario('estudiante'),
                'integrantes' => [$this->usuario('estudiante'), $this->usuario('estudiante')],
                'estado' => 'ejecucion',
                'visible' => true,
            ]);
        }

        $antes = $this->usuarios->consultasResumen;
        $body = $this->json($this->get('/api/v1/projects'));

        self::assertCount(4, $body['data']);
        self::assertSame(1, $this->usuarios->consultasResumen - $antes, 'autor e integrantes en una sola consulta');
    }

    // ---------------------------------------------------------------- detalle

    public function testDetallePublico(): void
    {
        $autor = $this->usuario('estudiante');
        $id = $this->documento([
            'autor_id' => $autor,
            'estado' => 'finalizado',
            'visible' => true,
            'contenido' => "# Título\n\n<script>alert(1)</script> **negrita**",
            'archivos_adjuntos' => [[
                'url' => 'https://res.cloudinary.com/demo/raw/upload/v1/siiis/documentos/abc123.pdf',
                'public_id' => 'siiis/documentos/abc123.pdf',
                'nombre_archivo' => 'informe.pdf',
            ]],
        ]);

        $response = $this->get('/api/v1/projects/' . $id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        $doc = $this->json($response)['documento'];
        // Markdown tal cual: el frontend lo muestra sin interpretar HTML (DEC-B37).
        self::assertSame("# Título\n\n<script>alert(1)</script> **negrita**", $doc['contenido']);
        self::assertSame([[
            'archivo' => 'abc123.pdf',
            'url' => 'https://res.cloudinary.com/demo/raw/upload/v1/siiis/documentos/abc123.pdf',
            'nombre_archivo' => 'informe.pdf',
        ]], $doc['archivos_adjuntos']);
        self::assertSame(['editar' => false, 'borrar' => false, 'publicar' => false], $doc['permisos']);
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
    }

    public function testDetalleNoVisibleResponde404SegunQuienConsulta(): void
    {
        $autor = $this->usuario('estudiante');
        [$tokenIntegrante, $integrante] = $this->sesionComo($this->app, 'estudiante');
        [$tokenOtro] = $this->sesionComo($this->app, 'estudiante_lider');
        [$tokenRevisor] = $this->sesionComo($this->app, 'docente_director');
        $borrador = $this->documento(['autor_id' => $autor, 'integrantes' => [$integrante], 'estado' => 'en_revision', 'visible' => false]);

        $this->assertError($this->get('/api/v1/projects/' . $borrador), 404, 'NOT_FOUND');
        $this->assertError($this->get('/api/v1/projects/' . $borrador, $tokenOtro), 404, 'NOT_FOUND');
        self::assertSame(200, $this->get('/api/v1/projects/' . $borrador, $tokenIntegrante)->getStatusCode());

        $revisor = $this->json($this->get('/api/v1/projects/' . $borrador, $tokenRevisor))['documento'];
        self::assertSame(['editar' => true, 'borrar' => true, 'publicar' => true], $revisor['permisos']);
    }

    public function testDetalleDeOtroModuloOIdInvalido404(): void
    {
        $articulo = $this->documento(['autor_id' => $this->usuario('estudiante'), 'categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true]);

        $response = $this->get('/api/v1/projects/' . $articulo);
        $this->assertError($response, 404, 'NOT_FOUND');
        self::assertSame('El proyecto no existe.', $this->json($response)['error']['message']);
        $this->assertError($this->get('/api/v1/projects/no-es-id'), 404, 'NOT_FOUND');
        $this->assertError($this->get('/api/v1/projects/' . new ObjectId()), 404, 'NOT_FOUND');
    }

    // ---------------------------------------------------------------- crear

    public function testCrearSinSesion401(): void
    {
        $this->assertError($this->send('POST', '/api/v1/projects', $this->datos()), 401, 'UNAUTHORIZED');
        self::assertSame([], $this->db->documentos);
    }

    public function testIntegranteCreaEnRevisionYOculto(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $companero = $this->usuario('estudiante');

        $response = $this->send('POST', '/api/v1/projects', $this->datos([
            'integrantes' => [(string) $companero, strtoupper((string) $companero)],
            'categoria' => 'articulo',
            'autor_id' => (string) new ObjectId(),
            'archivos_adjuntos' => [['url' => 'https://evil.test/x.pdf', 'public_id' => 'x']],
            'fecha_creacion' => '2000-01-01T00:00:00Z',
        ]), $token);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $doc = $this->json($response)['documento'];
        self::assertSame('proyecto', $doc['categoria']);
        self::assertSame('en_revision', $doc['estado']);
        self::assertFalse($doc['visible']);
        self::assertSame((string) $yo, $doc['autor']['id']);
        self::assertSame([(string) $companero], array_column($doc['integrantes'], 'id'));
        self::assertSame([], $doc['archivos_adjuntos']);
        self::assertSame(['editar' => true, 'borrar' => true, 'publicar' => false], $doc['permisos']);
        self::assertSame('2026-09-25T12:00:00Z', $doc['fecha_creacion']);

        $guardado = $this->db->documentos[$doc['id']];
        self::assertInstanceOf(ObjectId::class, $guardado['autor_id']);
        self::assertInstanceOf(ObjectId::class, $guardado['integrantes'][0]);
        self::assertInstanceOf(UTCDateTime::class, $guardado['fecha_actualizacion']);
        self::assertSame(
            ['_id', 'categoria', 'titulo', 'descripcion', 'contenido', 'autor_id', 'integrantes', 'archivos_adjuntos', 'estado', 'visible', 'fecha_creacion', 'fecha_actualizacion'],
            array_keys($guardado),
            'solo los campos del modelo 8.2',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function publicacionesNoPermitidas(): iterable
    {
        yield 'estado ejecucion' => [['estado' => 'ejecucion']];
        yield 'visible true' => [['visible' => true]];
    }

    /**
     * @param array<string, mixed> $cambios
     */
    #[DataProvider('publicacionesNoPermitidas')]
    public function testIntegranteNoPuedeCrearPublicado(array $cambios): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante_lider');

        $this->assertError($this->send('POST', '/api/v1/projects', $this->datos($cambios), $token), 403, 'FORBIDDEN');
        self::assertSame([], $this->db->documentos);
    }

    public function testIntegranteReenviaValoresPorDefecto(): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->send('POST', '/api/v1/projects', $this->datos(['estado' => 'en_revision', 'visible' => false]), $token);

        self::assertSame(201, $response->getStatusCode());
    }

    #[DataProvider('revisores')]
    public function testRevisorCreaPublicado(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);

        $response = $this->send('POST', '/api/v1/projects', $this->datos(['estado' => 'ejecucion', 'visible' => true]), $token);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('ejecucion', $this->json($response)['documento']['estado']);
        self::assertSame(1, $this->json($this->get('/api/v1/projects'))['meta']['total']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function datosInvalidos(): iterable
    {
        yield 'faltan obligatorios' => [['titulo' => null, 'descripcion' => '  '], ['titulo', 'descripcion']];
        yield 'título largo' => [['titulo' => str_repeat('t', 201)], ['titulo']];
        yield 'descripción larga' => [['descripcion' => str_repeat('d', 1001)], ['descripcion']];
        yield 'contenido largo' => [['contenido' => str_repeat('c', 50001)], ['contenido']];
        yield 'contenido no texto' => [['contenido' => ['md' => 'x']], ['contenido']];
        yield 'inyección en título' => [['titulo' => ['$ne' => '']], ['titulo']];
        yield 'integrantes objeto' => [['integrantes' => ['a' => '6adcffcf84e888428a0cbc83']], ['integrantes']];
        yield 'integrante no es id' => [['integrantes' => ['abc']], ['integrantes']];
        yield 'integrante numérico' => [['integrantes' => [123]], ['integrantes']];
        yield 'integrantes texto' => [['integrantes' => '6adcffcf84e888428a0cbc83'], ['integrantes']];
        yield 'estado de artículo' => [['estado' => 'publicado'], ['estado']];
        yield 'estado objeto' => [['estado' => ['$gt' => '']], ['estado']];
        yield 'visible como texto' => [['visible' => 'true'], ['visible']];
    }

    /**
     * @param array<string, mixed> $cambios
     * @param list<string>         $campos
     */
    #[DataProvider('datosInvalidos')]
    public function testValidaciones(array $cambios, array $campos): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');

        $response = $this->send('POST', '/api/v1/projects', $this->datos($cambios), $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertEqualsCanonicalizing($campos, array_keys($this->json($response)['error']['fields']));
        self::assertSame([], $this->db->documentos);
    }

    public function testLimiteDeIntegrantesConfigurable(): void
    {
        $app = $this->authApp(['DOCUMENTO_INTEGRANTES_MAX' => '2']);
        [$token] = $this->sesionComo($app, 'estudiante');
        $ids = array_map(fn (): string => (string) $this->usuario('estudiante'), range(1, 3));

        $response = $app->handle($this->request('POST', '/api/v1/projects', $this->datos(['integrantes' => $ids]), self::bearer($token)));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame('Máximo 2 integrantes.', $this->json($response)['error']['fields']['integrantes']);
    }

    public function testIntegrantesInexistentesOInactivos400(): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante');
        $inactivo = $this->usuario('estudiante', ['estado' => false]);
        $inexistente = new ObjectId();

        $response = $this->send('POST', '/api/v1/projects', $this->datos([
            'integrantes' => [(string) $this->usuario('estudiante'), (string) $inactivo, (string) $inexistente],
        ]), $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        $mensaje = $this->json($response)['error']['fields']['integrantes'];
        self::assertStringContainsString((string) $inactivo, $mensaje);
        self::assertStringContainsString((string) $inexistente, $mensaje);
        self::assertSame([], $this->db->documentos);
    }

    public function testCuerpoNoJson400(): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->app->handle($this->request('POST', '/api/v1/projects', '{"titulo":', self::bearer($token)));

        self::assertSame(400, $response->getStatusCode());
    }

    // ---------------------------------------------------------------- editar

    public function testAutorEditaMientrasEstaEnRevision(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'estado' => 'en_revision', 'visible' => false]);
        $this->clock->advance(3600);

        $response = $this->send('PATCH', '/api/v1/projects/' . $id, [
            'titulo' => 'Nuevo título',
            'contenido' => null,
            'estado' => 'en_revision',
            'visible' => false,
            'autor_id' => (string) new ObjectId(),
        ], $token);

        self::assertSame(200, $response->getStatusCode());
        $doc = $this->json($response)['documento'];
        self::assertSame('Nuevo título', $doc['titulo']);
        self::assertNull($doc['contenido']);
        self::assertSame('Descripción breve', $doc['descripcion'], 'lo no enviado no cambia');
        self::assertSame((string) $yo, $doc['autor']['id']);
        self::assertSame('2026-09-25T13:00:00Z', $doc['fecha_actualizacion']);
        self::assertNotSame($doc['fecha_creacion'], $doc['fecha_actualizacion']);
    }

    public function testAutorNoEditaLoPublicadoNiPublica(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $publicado = $this->documento(['autor_id' => $yo, 'estado' => 'ejecucion', 'visible' => true]);
        $borrador = $this->documento(['autor_id' => $yo, 'estado' => 'en_revision', 'visible' => false]);

        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $publicado, ['titulo' => 'x'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $borrador, ['estado' => 'ejecucion'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $borrador, ['visible' => true], $token), 403, 'FORBIDDEN');
        self::assertSame('Proyecto IT', $this->db->documentos[(string) $publicado]['titulo']);
        self::assertSame('en_revision', $this->db->documentos[(string) $borrador]['estado']);
    }

    public function testOtrosIntegrantesNoEditan(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante_lider');
        $autor = $this->usuario('estudiante');
        $publico = $this->documento(['autor_id' => $autor, 'estado' => 'ejecucion', 'visible' => true]);
        $comoIntegrante = $this->documento(['autor_id' => $autor, 'integrantes' => [$yo], 'estado' => 'en_revision', 'visible' => false]);
        $ajeno = $this->documento(['autor_id' => $autor, 'estado' => 'en_revision', 'visible' => false]);

        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $publico, ['titulo' => 'x'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $comoIntegrante, ['titulo' => 'x'], $token), 403, 'FORBIDDEN');
        // Un borrador ajeno "no existe" para quien no puede verlo.
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $ajeno, ['titulo' => 'x'], $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('revisores')]
    public function testRevisorPublicaYEditaLoPublicado(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->documento(['autor_id' => $this->usuario('estudiante'), 'estado' => 'en_revision', 'visible' => false]);

        $publicado = $this->send('PATCH', '/api/v1/projects/' . $id, ['estado' => 'ejecucion', 'visible' => true], $token);
        self::assertSame(200, $publicado->getStatusCode());
        self::assertSame(1, $this->json($this->get('/api/v1/projects'))['meta']['total']);

        $editado = $this->send('PATCH', '/api/v1/projects/' . $id, ['estado' => 'finalizado', 'titulo' => 'Cerrado'], $token);
        self::assertSame('finalizado', $this->json($editado)['documento']['estado']);
        self::assertSame('Cerrado', $this->json($editado)['documento']['titulo']);
    }

    public function testEdicionValidaEstadoYCuerpo(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->documento(['autor_id' => $this->usuario('estudiante'), 'estado' => 'en_revision', 'visible' => false]);

        $vacio = $this->send('PATCH', '/api/v1/projects/' . $id, ['autor_id' => 'x'], $token);
        $this->assertError($vacio, 400, 'VALIDATION_ERROR');
        self::assertStringContainsString('titulo, descripcion, contenido, integrantes, estado, visible', $this->json($vacio)['error']['message']);
        self::assertStringNotContainsString('categoria', $this->json($vacio)['error']['message']);

        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $id, ['estado' => 'publicado'], $token), 400, 'VALIDATION_ERROR');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $id, ['titulo' => ''], $token), 400, 'VALIDATION_ERROR');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $id, ['titulo' => 'x']), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('PATCH', '/api/v1/projects/' . new ObjectId(), ['titulo' => 'x'], $token), 404, 'NOT_FOUND');
    }

    public function testIntegrantesInactivosSePuedenConservarPeroNoAgregar(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $egresado = $this->usuario('estudiante', ['estado' => false]);
        $otroInactivo = $this->usuario('estudiante', ['estado' => false]);
        $id = $this->documento(['autor_id' => $yo, 'integrantes' => [$egresado], 'estado' => 'en_revision', 'visible' => false]);
        $nuevo = $this->usuario('estudiante');

        $ok = $this->send('PATCH', '/api/v1/projects/' . $id, ['integrantes' => [(string) $egresado, (string) $nuevo]], $token);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame([(string) $egresado, (string) $nuevo], array_column($this->json($ok)['documento']['integrantes'], 'id'));

        $mal = $this->send('PATCH', '/api/v1/projects/' . $id, ['integrantes' => [(string) $otroInactivo]], $token);
        $this->assertError($mal, 400, 'VALIDATION_ERROR');

        $vaciar = $this->send('PATCH', '/api/v1/projects/' . $id, ['integrantes' => []], $token);
        self::assertSame([], $this->json($vaciar)['documento']['integrantes']);
    }

    public function testEdicionDelAutorNoPisaUnaPublicacionSimultanea(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'estado' => 'en_revision', 'visible' => false]);
        // El revisor publica entre la lectura y la escritura del autor: el repositorio exige `en_revision`.
        $this->documentos->alLeer = function () use ($id): void {
            $this->db->documentos[(string) $id]['estado'] = 'ejecucion';
        };

        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $id, ['titulo' => 'x'], $token), 403, 'FORBIDDEN');
        self::assertSame('Proyecto IT', $this->db->documentos[(string) $id]['titulo']);
    }

    // ---------------------------------------------------------------- borrar

    public function testAutorBorraSuBorrador(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $id = $this->documento(['autor_id' => $yo, 'estado' => 'en_revision', 'visible' => false]);

        $response = $this->send('DELETE', '/api/v1/projects/' . $id, null, $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertArrayNotHasKey((string) $id, $this->db->documentos);
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $id, null, $token), 404, 'NOT_FOUND');
    }

    public function testBorradoRestringido(): void
    {
        [$token, $yo] = $this->sesionComo($this->app, 'estudiante');
        $publicado = $this->documento(['autor_id' => $yo, 'estado' => 'finalizado', 'visible' => true]);
        $ajenoPublico = $this->documento(['autor_id' => $this->usuario('estudiante'), 'estado' => 'ejecucion', 'visible' => true]);

        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $publicado), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $publicado, null, $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $ajenoPublico, null, $token), 403, 'FORBIDDEN');
        self::assertCount(2, $this->db->documentos);
    }

    #[DataProvider('revisores')]
    public function testRevisorBorraCualquierDocumento(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->documento(['autor_id' => $this->usuario('estudiante'), 'estado' => 'finalizado', 'visible' => true]);

        self::assertSame(204, $this->send('DELETE', '/api/v1/projects/' . $id, null, $token)->getStatusCode());
        self::assertSame([], $this->db->documentos);
    }

    public function testNoSeTocaUnArticuloDesdeProjects(): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');
        $articulo = $this->documento(['autor_id' => $this->usuario('estudiante'), 'categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true]);

        $this->assertError($this->send('PATCH', '/api/v1/projects/' . $articulo, ['titulo' => 'x'], $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $articulo, null, $token), 404, 'NOT_FOUND');
        self::assertArrayHasKey((string) $articulo, $this->db->documentos);
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
            'titulo' => 'Proyecto nuevo',
            'descripcion' => 'Resumen del proyecto',
            'contenido' => "## Objetivo\n\nTexto en **Markdown**.",
        ];
    }
}
