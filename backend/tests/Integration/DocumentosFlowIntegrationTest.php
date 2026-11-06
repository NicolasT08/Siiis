<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Psr\Http\Message\ResponseInterface;
use Siiis\Config\Application;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Services\CloudinaryService;
use Siiis\Services\MailService;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\FakeMailService;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

/**
 * Fase 3 de punta a punta contra Atlas (base de pruebas), con la app real: paginación completa, el público
 * nunca ve borradores, ciclo crear → publicar → editar → borrar en `/projects` y `/articles`, y autor e
 * integrantes resueltos con una sola consulta a `Usuarios`. Correo y Cloudinary simulados (los adjuntos
 * reales tienen su propia prueba). Los usuarios llevan el prefijo `$marca`; ellos y sus documentos se borran
 * al terminar.
 */
final class DocumentosFlowIntegrationTest extends IntegrationTestCase
{
    private const PASSWORD = 'Clave-Integracion-2026';

    private string $marca;

    /** @var list<ObjectId> */
    private array $usuarios = [];

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = 'it-docs-' . bin2hex(random_bytes(4));
        $this->app = Application::create($this->settings(), [
            Mongo::class => $this->mongo(),
            MailService::class => new FakeMailService(),
            CloudinaryService::class => new FakeCloudinaryService(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->usuarios === []) {
            return;
        }
        $ids = ['$in' => $this->usuarios];
        $this->mongo()->collection(Collections::DOCUMENTOS)->deleteMany(['autor_id' => $ids]);
        $this->mongo()->collection(Collections::SESIONES)->deleteMany(['usuario_id' => $ids]);
        $this->mongo()->collection(Collections::USUARIOS)->deleteMany(['_id' => $ids]);
        $this->mongo()->collection(Collections::CREDENCIALES)->deleteMany(['usuario_id' => $ids]);
        $this->usuarios = [];
    }

    public function testCicloCrearPublicarEditarYBorrarUnProyecto(): void
    {
        [$autor, $tokenAutor] = $this->cuenta('autor', 'estudiante');
        [$integrante, $tokenIntegrante] = $this->cuenta('integrante', 'estudiante');
        [, $tokenOtro] = $this->cuenta('otro', 'estudiante');
        [, $tokenRevisor] = $this->cuenta('revisor', 'docente_director');

        // Crear: queda en revisión y oculto, aunque el autor pida publicarlo.
        $this->assertError($this->call('POST', '/projects', ['titulo' => 'x', 'descripcion' => 'y', 'visible' => true], $tokenAutor), 403, 'FORBIDDEN');
        $creado = $this->call('POST', '/projects', [
            'titulo' => 'Proyecto IT',
            'descripcion' => 'Flujo completo',
            'contenido' => "## Objetivo\n\nTexto en **Markdown**.",
            'integrantes' => [$integrante],
        ], $tokenAutor);
        self::assertSame(201, $creado->getStatusCode(), (string) $creado->getBody());
        $doc = self::json($creado)['documento'];
        $id = $doc['id'];
        self::assertSame(['en_revision', false], [$doc['estado'], $doc['visible']]);
        self::assertSame($autor, $doc['autor']['id']);
        self::assertSame([$integrante], array_column($doc['integrantes'], 'id'));
        self::assertSame(['editar' => true, 'borrar' => true, 'publicar' => false], $doc['permisos']);

        // Lo guardado en Atlas respeta el modelo de datos (spec 8.2).
        $guardado = $this->mongo()->collection(Collections::DOCUMENTOS)->findOne(['_id' => new ObjectId($id)]);
        self::assertIsArray($guardado);
        self::assertSame('proyecto', $guardado['categoria']);
        self::assertEquals(new ObjectId($autor), $guardado['autor_id']);
        self::assertEquals([new ObjectId($integrante)], $guardado['integrantes']);
        self::assertSame([], $guardado['archivos_adjuntos']);
        self::assertInstanceOf(UTCDateTime::class, $guardado['fecha_creacion']);
        self::assertInstanceOf(UTCDateTime::class, $guardado['fecha_actualizacion']);

        // El borrador: lo ven el autor, el integrante y el revisor; ni el público ni otro integrante.
        $this->assertError($this->call('GET', "/projects/$id"), 404, 'NOT_FOUND');
        $this->assertError($this->call('GET', "/projects/$id", null, $tokenOtro), 404, 'NOT_FOUND');
        self::assertSame(['editar' => false, 'borrar' => false, 'publicar' => false], self::json($this->call('GET', "/projects/$id", null, $tokenIntegrante))['documento']['permisos']);
        self::assertSame(['editar' => true, 'borrar' => true, 'publicar' => true], self::json($this->call('GET', "/projects/$id", null, $tokenRevisor))['documento']['permisos']);
        self::assertSame([], self::json($this->call('GET', "/projects?autor=$autor"))['data']);
        self::assertSame([$id], array_column(self::json($this->call('GET', "/projects?integrante=$integrante", null, $tokenIntegrante))['data'], 'id'));

        // El autor edita mientras está en revisión; el integrante no.
        $editado = $this->call('PATCH', "/projects/$id", ['titulo' => 'Proyecto IT editado'], $tokenAutor);
        self::assertSame(200, $editado->getStatusCode(), (string) $editado->getBody());
        $this->assertError($this->call('PATCH', "/projects/$id", ['titulo' => 'No'], $tokenIntegrante), 403, 'FORBIDDEN');
        $this->assertError($this->call('PATCH', "/projects/$id", ['estado' => 'ejecucion'], $tokenAutor), 403, 'FORBIDDEN');

        // El revisor publica: ahora es público y el autor ya no edita ni borra.
        $publicado = $this->call('PATCH', "/projects/$id", ['estado' => 'ejecucion', 'visible' => true], $tokenRevisor);
        self::assertSame(200, $publicado->getStatusCode(), (string) $publicado->getBody());
        $publico = $this->call('GET', "/projects/$id");
        self::assertSame(200, $publico->getStatusCode());
        self::assertSame('public, max-age=60', $publico->getHeaderLine('Cache-Control'));
        self::assertSame('Proyecto IT editado', self::json($publico)['documento']['titulo']);
        self::assertSame([$id], array_column(self::json($this->call('GET', "/projects?autor=$autor"))['data'], 'id'));
        $this->assertError($this->call('PATCH', "/projects/$id", ['titulo' => 'Tarde'], $tokenAutor), 403, 'FORBIDDEN');
        $this->assertError($this->call('DELETE', "/projects/$id", null, $tokenAutor), 403, 'FORBIDDEN');

        // El revisor sigue editando (finaliza) y borra: borrado físico.
        self::assertSame('finalizado', self::json($this->call('PATCH', "/projects/$id", ['estado' => 'finalizado'], $tokenRevisor))['documento']['estado']);
        self::assertSame(204, $this->call('DELETE', "/projects/$id", null, $tokenRevisor)->getStatusCode());
        self::assertNull($this->mongo()->collection(Collections::DOCUMENTOS)->findOne(['_id' => new ObjectId($id)]));
        $this->assertError($this->call('GET', "/projects/$id", null, $tokenRevisor), 404, 'NOT_FOUND');
    }

    public function testArticulosYNoticiasConPublicacionYSinCruceDeModulos(): void
    {
        [$autor, $tokenAutor] = $this->cuenta('autor', 'estudiante_lider');
        [, $tokenRevisor] = $this->cuenta('revisor', 'administrador');

        $noticia = self::json($this->call('POST', '/articles', ['categoria' => 'noticia', 'titulo' => 'Noticia IT', 'descripcion' => 'd'], $tokenAutor))['documento'];
        $articulo = self::json($this->call('POST', '/articles', ['categoria' => 'articulo', 'titulo' => 'Artículo IT', 'descripcion' => 'd'], $tokenAutor))['documento'];
        self::assertSame(['noticia', 'articulo'], [$noticia['categoria'], $articulo['categoria']]);

        // Un estado de proyecto no vale para un artículo.
        $this->assertError($this->call('PATCH', '/articles/' . $noticia['id'], ['estado' => 'ejecucion'], $tokenRevisor), 400, 'VALIDATION_ERROR');
        self::assertSame(200, $this->call('PATCH', '/articles/' . $noticia['id'], ['estado' => 'publicado', 'visible' => true], $tokenRevisor)->getStatusCode());

        // El público ve solo la noticia publicada; los proyectos no incluyen artículos ni al revés.
        self::assertSame([$noticia['id']], array_column(self::json($this->call('GET', "/articles?autor=$autor"))['data'], 'id'));
        self::assertSame([$noticia['id']], array_column(self::json($this->call('GET', "/articles?autor=$autor&categoria=noticia"))['data'], 'id'));
        self::assertSame([], self::json($this->call('GET', "/articles?autor=$autor&categoria=articulo"))['data']);
        self::assertSame([], self::json($this->call('GET', "/projects?autor=$autor", null, $tokenRevisor))['data']);
        $this->assertError($this->call('GET', '/projects/' . $noticia['id'], null, $tokenRevisor), 404, 'NOT_FOUND');
        $this->assertError($this->call('DELETE', '/projects/' . $articulo['id'], null, $tokenRevisor), 404, 'NOT_FOUND');

        // El autor borra su artículo mientras está en revisión.
        self::assertSame(204, $this->call('DELETE', '/articles/' . $articulo['id'], null, $tokenAutor)->getStatusCode());
        self::assertSame(1, $this->mongo()->collection(Collections::DOCUMENTOS)->countDocuments(['autor_id' => new ObjectId($autor)]));
    }

    public function testPaginacionCompletaYElPublicoNuncaVeBorradores(): void
    {
        [$autor, $tokenAutor] = $this->cuenta('revisor', 'docente_director');
        [, $tokenOtro] = $this->cuenta('otro', 'estudiante');
        $casos = [
            ['ejecucion', true], ['en_revision', false], ['finalizado', true], ['ejecucion', false],
            ['en_revision', true], ['ejecucion', true], ['finalizado', false], ['finalizado', true],
        ];
        $creados = [];
        foreach ($casos as $i => [$estado, $visible]) {
            $r = $this->call('POST', '/projects', ['titulo' => "P$i", 'descripcion' => 'd', 'estado' => $estado, 'visible' => $visible], $tokenAutor);
            self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
            $creados[] = ['id' => self::json($r)['documento']['id'], 'publico' => $visible && $estado !== 'en_revision'];
        }
        // Más recientes primero.
        $publicos = array_reverse(array_column(array_filter($creados, static fn (array $c): bool => $c['publico']), 'id'));
        $todos = array_reverse(array_column($creados, 'id'));

        self::assertSame($publicos, $this->recorrer("/projects?autor=$autor", null, 3));
        self::assertSame($publicos, $this->recorrer("/projects?autor=$autor", $tokenOtro, 2));
        self::assertSame($todos, $this->recorrer("/projects?autor=$autor", $tokenAutor, 3));
        // Pedir borradores sin permiso no los muestra, ni con ?visible=false (se ignora para no revisores).
        self::assertSame([], self::json($this->call('GET', "/projects?autor=$autor&estado=en_revision"))['data']);
        self::assertSame($publicos, $this->recorrer("/projects?autor=$autor&visible=false", $tokenOtro, 50));
        // El revisor sí filtra por visible: los 3 ocultos, incluido el borrador.
        self::assertSame(
            [$todos[1], $todos[4], $todos[6]],
            $this->recorrer("/projects?autor=$autor&visible=false", $tokenAutor, 50),
        );

        // Recorrido completo del listado público de siiis_test: nada en revisión ni oculto.
        foreach (['/projects', '/articles'] as $ruta) {
            $primera = self::json($this->call('GET', "$ruta?per_page=50"));
            for ($page = 1; $page <= $primera['meta']['total_pages']; ++$page) {
                foreach (self::json($this->call('GET', "$ruta?per_page=50&page=$page"))['data'] as $d) {
                    self::assertTrue($d['visible'], "$ruta mostró un documento oculto: {$d['id']}");
                    self::assertNotSame('en_revision', $d['estado'], "$ruta mostró un borrador: {$d['id']}");
                }
            }
        }
    }

    public function testAutorEIntegrantesSeResuelvenEnUnaSolaConsulta(): void
    {
        [$autor, $tokenAutor] = $this->cuenta('revisor', 'superadministrador');
        $integrantes = [];
        for ($i = 0; $i < 3; ++$i) {
            $integrantes[] = $this->cuenta("integrante$i", 'estudiante', false)[0];
        }
        foreach ([[0], [1, 2], [0, 1, 2]] as $n => $indices) {
            $r = $this->call('POST', '/projects', [
                'titulo' => "Con integrantes $n",
                'descripcion' => 'd',
                'integrantes' => array_map(static fn (int $k): string => $integrantes[$k], $indices),
                'estado' => 'ejecucion',
                'visible' => true,
            ], $tokenAutor);
            self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        }
        // Un integrante egresa: sigue apareciendo en los documentos (DEC-B38).
        $this->mongo()->collection(Collections::USUARIOS)->updateOne(['_id' => new ObjectId($integrantes[2])], ['$set' => ['estado' => false]]);

        $comandos = new class () implements CommandSubscriber {
            /** @var list<string> */
            public array $usuarios = [];

            public function commandStarted(CommandStartedEvent $event): void
            {
                $comando = (array) $event->getCommand();
                if ($event->getCommandName() === 'find' && ($comando['find'] ?? null) === Collections::USUARIOS) {
                    $this->usuarios[] = $event->getCommandName();
                }
            }

            public function commandSucceeded(CommandSucceededEvent $event): void
            {
            }

            public function commandFailed(CommandFailedEvent $event): void
            {
            }
        };
        addSubscriber($comandos);
        try {
            $response = $this->call('GET', "/projects?autor=$autor");
        } finally {
            removeSubscriber($comandos);
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $comandos->usuarios, 'Autor e integrantes de toda la página deben salir de una sola consulta (sin N+1).');
        $data = self::json($response)['data'];
        self::assertCount(3, $data);
        self::assertSame([$autor, $autor, $autor], array_column(array_column($data, 'autor'), 'id'));
        self::assertSame(
            [[$integrantes[0], $integrantes[1], $integrantes[2]], [$integrantes[1], $integrantes[2]], [$integrantes[0]]],
            array_map(static fn (array $d): array => array_column($d['integrantes'], 'id'), $data),
        );
        foreach ($data as $d) {
            foreach ([$d['autor'], ...$d['integrantes']] as $persona) {
                self::assertSame(['id', 'nombre', 'apellido', 'rol', 'foto_perfil'], array_keys($persona));
            }
        }
        // Un egresado no se puede agregar a un documento nuevo.
        $this->assertError($this->call('POST', '/projects', ['titulo' => 't', 'descripcion' => 'd', 'integrantes' => [$integrantes[2]]], $tokenAutor), 400, 'VALIDATION_ERROR');
    }

    /**
     * Ids de todas las páginas, comprobando que el total es estable, que no se repiten y que la página
     * siguiente a la última está vacía.
     *
     * @return list<string>
     */
    private function recorrer(string $ruta, ?string $token, int $perPage): array
    {
        $separador = str_contains($ruta, '?') ? '&' : '?';
        $primera = self::json($this->call('GET', "$ruta{$separador}per_page=$perPage", null, $token));
        $total = $primera['meta']['total'];
        self::assertSame(max(1, (int) ceil($total / $perPage)), max(1, $primera['meta']['total_pages']));

        $ids = [];
        for ($page = 1; $page <= $primera['meta']['total_pages']; ++$page) {
            $pagina = self::json($this->call('GET', "$ruta{$separador}per_page=$perPage&page=$page", null, $token));
            self::assertSame($total, $pagina['meta']['total']);
            self::assertLessThanOrEqual($perPage, count($pagina['data']));
            array_push($ids, ...array_column($pagina['data'], 'id'));
        }
        self::assertCount($total, $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'Un documento apareció en dos páginas.');
        $siguiente = $primera['meta']['total_pages'] + 1;
        self::assertSame([], self::json($this->call('GET', "$ruta{$separador}per_page=$perPage&page=$siguiente", null, $token))['data']);

        return $ids;
    }

    /**
     * Cuenta sembrada directamente en Atlas y, si `$conSesion`, con sesión iniciada por la API.
     *
     * @return array{string, string} [usuario_id, token ('' sin sesión)]
     */
    private function cuenta(string $alias, string $rol, bool $conSesion = true): array
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
        if (!$conSesion) {
            return [(string) $usuarioId, ''];
        }

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
        $request = (new ServerRequestFactory())->createServerRequest($method, "/api/v1$path", ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)]);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer $token");
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        }

        return $this->app->handle($request);
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
}
