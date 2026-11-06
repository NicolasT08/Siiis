<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Reseñas (DEC-B43 a DEC-B45, DEC-B48): GET/POST /reviews y moderación con PATCH/DELETE /reviews/{id}.
 */
final class ReviewsApiTest extends AuthTestCase
{
    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    private int $segundos = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function moderadores(): iterable
    {
        yield 'superadministrador' => ['superadministrador'];
        yield 'administrador' => ['administrador'];
        yield 'docente_director' => ['docente_director'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noModeradores(): iterable
    {
        yield 'estudiante_lider' => ['estudiante_lider'];
        yield 'estudiante' => ['estudiante'];
    }

    public function testVisitanteCreaReseñaOcultaSinUsuario(): void
    {
        $response = $this->send('POST', '/api/v1/reviews', [
            'nombre_visible' => "  María\n  Gómez ",
            'comentario' => '  Excelente semillero.  ',
            'calificacion' => 5,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $resena = $this->json($response)['resena'];
        self::assertSame(['id', 'usuario_id', 'nombre_visible', 'comentario', 'calificacion', 'estado', 'fecha'], array_keys($resena));
        self::assertNull($resena['usuario_id']);
        self::assertSame('María Gómez', $resena['nombre_visible']);
        self::assertSame('Excelente semillero.', $resena['comentario']);
        self::assertSame(5, $resena['calificacion']);
        self::assertSame('oculta', $resena['estado']);
        self::assertSame('2026-09-25T12:00:00Z', $resena['fecha']);

        $guardada = $this->db->resenas[$resena['id']];
        self::assertNull($guardada['usuario_id']);
        self::assertInstanceOf(UTCDateTime::class, $guardada['fecha']);
        self::assertSame(['_id', 'usuario_id', 'nombre_visible', 'comentario', 'calificacion', 'estado', 'fecha'], array_keys($guardada));
    }

    public function testIntegranteConSesionPublicaConSuNombreYSinPoderSuplantar(): void
    {
        [$token, $usuarioId] = $this->sesionComo($this->app, 'estudiante', ['nombre' => 'Luis', 'apellido' => 'Rojas']);

        $response = $this->send('POST', '/api/v1/reviews', [
            'nombre_visible' => 'Otra Persona',
            'comentario' => 'Muy buen ambiente.',
            'usuario_id' => (string) new ObjectId(),
            'estado' => 'oculta',
            'fecha' => '2020-01-01T00:00:00Z',
        ], $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $resena = $this->json($response)['resena'];
        self::assertSame((string) $usuarioId, $resena['usuario_id']);
        self::assertSame('Luis Rojas', $resena['nombre_visible']);
        self::assertSame('visible', $resena['estado']);
        self::assertNull($resena['calificacion']);
        self::assertSame('2026-09-25T12:00:00Z', $resena['fecha']);
        self::assertEquals($usuarioId, $this->db->resenas[$resena['id']]['usuario_id']);
    }

    public function testConSesionNoExigeNombreVisible(): void
    {
        [$token] = $this->sesionComo($this->app, 'estudiante');

        self::assertSame(201, $this->send('POST', '/api/v1/reviews', ['comentario' => 'Bien.'], $token)->getStatusCode());
    }

    public function testTokenInvalidoNoPublicaComoAnonimo(): void
    {
        $response = $this->send('POST', '/api/v1/reviews', ['nombre_visible' => 'Ana', 'comentario' => 'Hola'], 'token-vencido');

        $this->assertError($response, 401, 'UNAUTHORIZED');
        self::assertSame([], $this->db->resenas);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function cuerposInvalidos(): iterable
    {
        yield 'vacío' => [(object) [], ['nombre_visible', 'comentario']];
        yield 'solo espacios' => [['nombre_visible' => '  ', 'comentario' => "\n "], ['nombre_visible', 'comentario']];
        yield 'nombre largo' => [['nombre_visible' => str_repeat('a', 81), 'comentario' => 'x'], ['nombre_visible']];
        yield 'comentario largo' => [['nombre_visible' => 'Ana', 'comentario' => str_repeat('é', 1001)], ['comentario']];
        yield 'calificación 0' => [['nombre_visible' => 'Ana', 'comentario' => 'x', 'calificacion' => 0], ['calificacion']];
        yield 'calificación 6' => [['nombre_visible' => 'Ana', 'comentario' => 'x', 'calificacion' => 6], ['calificacion']];
        yield 'calificación texto' => [['nombre_visible' => 'Ana', 'comentario' => 'x', 'calificacion' => '5'], ['calificacion']];
        yield 'calificación decimal' => [['nombre_visible' => 'Ana', 'comentario' => 'x', 'calificacion' => 4.5], ['calificacion']];
        yield 'inyección en nombre' => [['nombre_visible' => ['$ne' => ''], 'comentario' => 'x'], ['nombre_visible']];
        yield 'inyección en comentario' => [['nombre_visible' => 'Ana', 'comentario' => ['$gt' => '']], ['comentario']];
    }

    /**
     * @param list<string> $campos
     */
    #[DataProvider('cuerposInvalidos')]
    public function testValidacion(mixed $body, array $campos): void
    {
        $response = $this->send('POST', '/api/v1/reviews', $body);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        $fields = $this->json($response)['error']['fields'];
        self::assertSame($campos, array_keys($fields));
        self::assertSame([], $this->db->resenas);
    }

    public function testCuerpoQueNoEsObjeto(): void
    {
        $this->assertError($this->send('POST', '/api/v1/reviews', '"hola"'), 400, 'INVALID_JSON');
    }

    public function testComentarioDeMilCaracteresConTildesEsValido(): void
    {
        $response = $this->send('POST', '/api/v1/reviews', ['nombre_visible' => 'Ana', 'comentario' => str_repeat('é', 1000)]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testRateLimitPorIp(): void
    {
        $app = $this->authApp(['RATE_LIMIT_REVIEWS_MAX' => '2']);
        $body = ['nombre_visible' => 'Ana', 'comentario' => 'Hola'];

        self::assertSame(201, $app->handle($this->request('POST', '/api/v1/reviews', $body))->getStatusCode());
        // Un envío inválido también consume cupo (DEC-B48).
        $this->assertError($app->handle($this->request('POST', '/api/v1/reviews', (object) [])), 400, 'VALIDATION_ERROR');
        $bloqueada = $app->handle($this->request('POST', '/api/v1/reviews', $body));
        $this->assertError($bloqueada, 429, 'RATE_LIMITED');
        self::assertSame('3600', $bloqueada->getHeaderLine('Retry-After'));
        self::assertCount(1, $this->db->resenas);

        // Otra IP no está bloqueada.
        $otra = $app->handle($this->request('POST', '/api/v1/reviews', $body, [], '198.51.100.7'));
        self::assertSame(201, $otra->getStatusCode());
    }

    public function testListadoPublicoSoloVisiblesConResumen(): void
    {
        $visible1 = $this->resena(['estado' => 'visible', 'calificacion' => 5]);
        $this->resena(['estado' => 'oculta', 'calificacion' => 1]);
        $visible2 = $this->resena(['estado' => 'visible', 'calificacion' => 4]);
        $visible3 = $this->resena(['estado' => 'visible', 'calificacion' => null]);

        $response = $this->send('GET', '/api/v1/reviews');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Authorization', $response->getHeaderLine('Vary'));
        $body = $this->json($response);
        self::assertSame([(string) $visible3, (string) $visible2, (string) $visible1], array_column($body['data'], 'id'));
        self::assertSame(['page' => 1, 'per_page' => 10, 'total' => 3, 'total_pages' => 1], $body['meta']);
        self::assertSame(['promedio' => 4.5, 'calificaciones' => 2], $body['resumen']);
    }

    public function testResumenSinCalificacionesYPromedioRedondeado(): void
    {
        self::assertSame(['promedio' => null, 'calificaciones' => 0], $this->json($this->send('GET', '/api/v1/reviews'))['resumen']);

        $this->resena(['estado' => 'visible', 'calificacion' => 5]);
        $this->resena(['estado' => 'visible', 'calificacion' => 4]);
        $this->resena(['estado' => 'visible', 'calificacion' => 4]);

        self::assertSame(['promedio' => 4.3, 'calificaciones' => 3], $this->json($this->send('GET', '/api/v1/reviews'))['resumen']);
    }

    #[DataProvider('noModeradores')]
    public function testIntegranteNoVeOcultasNiFiltraPorEstado(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $visible = $this->resena(['estado' => 'visible']);
        $this->resena(['estado' => 'oculta']);

        $response = $this->send('GET', '/api/v1/reviews?estado=oculta', null, $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([(string) $visible], array_column($this->json($response)['data'], 'id'));
    }

    #[DataProvider('moderadores')]
    public function testModeradorVeTodasYFiltra(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $visible = $this->resena(['estado' => 'visible']);
        $oculta = $this->resena(['estado' => 'oculta']);

        self::assertSame(
            [(string) $oculta, (string) $visible],
            array_column($this->json($this->send('GET', '/api/v1/reviews', null, $token))['data'], 'id'),
        );
        self::assertSame(
            [(string) $oculta],
            array_column($this->json($this->send('GET', '/api/v1/reviews?estado=oculta', null, $token))['data'], 'id'),
        );
    }

    public function testPaginacion(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = (string) $this->resena(['estado' => 'visible']);
        }

        $body = $this->json($this->send('GET', '/api/v1/reviews?page=2&per_page=2'));

        self::assertSame([$ids[2], $ids[1]], array_column($body['data'], 'id'));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 5, 'total_pages' => 3], $body['meta']);
        self::assertSame([], $this->json($this->send('GET', '/api/v1/reviews?page=9'))['data']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function queriesInvalidas(): iterable
    {
        yield 'page 0' => ['page=0', 'page'];
        yield 'per_page grande' => ['per_page=51', 'per_page'];
        yield 'page texto' => ['page=uno', 'page'];
        yield 'page arreglo' => ['page[$ne]=1', 'page'];
    }

    #[DataProvider('queriesInvalidas')]
    public function testQueryInvalida(string $query, string $campo): void
    {
        $response = $this->send('GET', '/api/v1/reviews?' . $query);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey($campo, $this->json($response)['error']['fields']);
    }

    public function testEstadoInvalidoParaModerador(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->send('GET', '/api/v1/reviews?estado=borrada', null, $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('estado', $this->json($response)['error']['fields']);
    }

    #[DataProvider('moderadores')]
    public function testModeradorApruebaYOculta(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->resena(['estado' => 'oculta']);

        $response = $this->send('PATCH', '/api/v1/reviews/' . $id, ['estado' => 'visible', 'comentario' => 'cambiado'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $resena = $this->json($response)['resena'];
        self::assertSame('visible', $resena['estado']);
        self::assertSame('Comentario de prueba', $resena['comentario']);
        self::assertSame('visible', $this->db->resenas[(string) $id]['estado']);

        $this->send('PATCH', '/api/v1/reviews/' . $id, ['estado' => 'oculta'], $token);
        self::assertSame('oculta', $this->db->resenas[(string) $id]['estado']);
    }

    #[DataProvider('moderadores')]
    public function testModeradorBorra(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);
        $id = $this->resena(['estado' => 'visible']);

        $response = $this->send('DELETE', '/api/v1/reviews/' . $id, null, $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertArrayNotHasKey((string) $id, $this->db->resenas);
        $this->assertError($this->send('DELETE', '/api/v1/reviews/' . $id, null, $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('noModeradores')]
    public function testNoModeradorNoModera(string $rol): void
    {
        [$token, $usuarioId] = $this->sesionComo($this->app, $rol);
        // Ni siquiera sobre su propia reseña.
        $id = $this->resena(['estado' => 'visible', 'usuario_id' => $usuarioId]);

        $this->assertError($this->send('PATCH', '/api/v1/reviews/' . $id, ['estado' => 'oculta'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->send('DELETE', '/api/v1/reviews/' . $id, null, $token), 403, 'FORBIDDEN');
        self::assertSame('visible', $this->db->resenas[(string) $id]['estado']);
    }

    public function testModerarSinSesion(): void
    {
        $id = $this->resena(['estado' => 'oculta']);

        $this->assertError($this->send('PATCH', '/api/v1/reviews/' . $id, ['estado' => 'visible']), 401, 'UNAUTHORIZED');
        $this->assertError($this->send('DELETE', '/api/v1/reviews/' . $id), 401, 'UNAUTHORIZED');
    }

    public function testModerarIdInexistenteOInvalido(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->send('PATCH', '/api/v1/reviews/' . new ObjectId(), ['estado' => 'visible'], $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('PATCH', '/api/v1/reviews/no-es-un-id', ['estado' => 'visible'], $token), 404, 'NOT_FOUND');
        $this->assertError($this->send('DELETE', '/api/v1/reviews/no-es-un-id', null, $token), 404, 'NOT_FOUND');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function estadosInvalidos(): iterable
    {
        yield 'sin estado' => [(object) []];
        yield 'otro valor' => [['estado' => 'publicada']];
        yield 'mayúsculas' => [['estado' => 'VISIBLE']];
        yield 'arreglo' => [['estado' => ['$ne' => 'oculta']]];
        yield 'booleano' => [['estado' => true]];
    }

    #[DataProvider('estadosInvalidos')]
    public function testModerarConEstadoInvalido(mixed $body): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $id = $this->resena(['estado' => 'oculta']);

        $response = $this->send('PATCH', '/api/v1/reviews/' . $id, $body, $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('estado', $this->json($response)['error']['fields']);
        self::assertSame('oculta', $this->db->resenas[(string) $id]['estado']);
    }

    private function send(string $method, string $path, mixed $body = null, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request($method, $path, $body, $token === null ? [] : self::bearer($token)));
    }

    /**
     * Inserta una reseña directamente; cada una un segundo más reciente que la anterior.
     *
     * @param array<string, mixed> $campos
     */
    private function resena(array $campos): ObjectId
    {
        $id = new ObjectId();
        $this->db->resenas[(string) $id] = $campos + [
            '_id' => $id,
            'usuario_id' => null,
            'nombre_visible' => 'Visitante',
            'comentario' => 'Comentario de prueba',
            'calificacion' => null,
            'estado' => 'visible',
            'fecha' => new UTCDateTime(($this->clock->now()->getTimestamp() + ++$this->segundos) * 1000),
        ];

        return $id;
    }
}
