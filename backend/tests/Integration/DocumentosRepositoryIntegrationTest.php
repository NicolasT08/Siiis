<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Repositories\DocumentoCriterios;
use Siiis\Repositories\DocumentosRepository;
use Siiis\Repositories\UsuariosRepository;

/**
 * DocumentosRepository y UsuariosRepository::findResumenesByIds contra Atlas (siiis_test). Cada prueba usa
 * su propio autor como marca y borra lo que creó.
 */
final class DocumentosRepositoryIntegrationTest extends IntegrationTestCase
{
    private ObjectId $autor;

    private int $ms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autor = new ObjectId();
        $this->ms = (int) (microtime(true) * 1000);
    }

    protected function tearDown(): void
    {
        if (self::$mongo !== null) {
            $this->mongo()->collection(Collections::DOCUMENTOS)->deleteMany(['autor_id' => $this->autor]);
        }
    }

    public function testFiltrosDeAlcanceContraMongo(): void
    {
        $actor = new ObjectId();
        $publico = $this->insertar(['estado' => 'ejecucion', 'visible' => true]);
        $borradorVisible = $this->insertar(['estado' => 'en_revision', 'visible' => true]);
        $oculto = $this->insertar(['estado' => 'finalizado', 'visible' => false]);
        $comoIntegrante = $this->insertar(['estado' => 'en_revision', 'visible' => false, 'integrantes' => [$actor]]);
        $articulo = $this->insertar(['categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true]);

        $ids = fn (DocumentoCriterios $c): array => $this->ids($c);

        self::assertSame([$publico], $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_PUBLICO)));
        self::assertSame(
            [$comoIntegrante, $publico],
            $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_PROPIO, $actor)),
        );
        self::assertSame(
            [$comoIntegrante, $oculto, $borradorVisible, $publico],
            $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_TODO)),
        );
        self::assertSame(
            [$oculto],
            $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_TODO, visible: false, estado: 'finalizado')),
        );
        self::assertSame(
            [$comoIntegrante],
            $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_PROPIO, $actor, estado: 'en_revision')),
        );
        self::assertSame(
            [$comoIntegrante],
            $ids($this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_TODO, integrante: $actor)),
        );
        self::assertSame(
            [$articulo],
            $ids($this->criterios(['articulo', 'noticia'], DocumentoCriterios::ALCANCE_PUBLICO)),
        );
    }

    public function testPaginacionProyeccionYOrden(): void
    {
        $creados = [];
        for ($i = 0; $i < 5; ++$i) {
            $creados[] = $this->insertar(['estado' => 'ejecucion', 'visible' => true, 'contenido' => str_repeat('x', 100)]);
        }
        $repo = new DocumentosRepository($this->mongo());
        $criterios = $this->criterios(['proyecto'], DocumentoCriterios::ALCANCE_TODO);

        $vistos = [];
        for ($page = 1; $page <= 3; ++$page) {
            ['items' => $items, 'total' => $total] = $repo->paginar($criterios, $page, 2);
            self::assertSame(5, $total);
            foreach ($items as $item) {
                self::assertArrayNotHasKey('contenido', $item);
                self::assertArrayHasKey('titulo', $item);
                $vistos[] = (string) $item['_id'];
            }
        }

        self::assertSame(array_reverse($creados), $vistos, 'más recientes primero, sin repetidos');
        self::assertSame([], $repo->paginar($criterios, 4, 2)['items']);
    }

    public function testCrearActualizarYEliminarCondicionados(): void
    {
        $repo = new DocumentosRepository($this->mongo());
        $id = new ObjectId();
        $repo->insertar($this->documento($id, ['estado' => 'en_revision', 'visible' => false]));

        self::assertSame('Contenido IT', $repo->findById($id)['contenido']);
        self::assertTrue($repo->actualizar($id, ['titulo' => 'Editado'], 'en_revision'));
        self::assertTrue($repo->actualizar($id, ['estado' => 'ejecucion']));
        // El autor ya no puede escribir: el documento salió de revisión.
        self::assertFalse($repo->actualizar($id, ['titulo' => 'Tarde'], 'en_revision'));
        self::assertSame('Editado', $repo->findById($id)['titulo']);

        self::assertFalse($repo->eliminar($id, 'en_revision'));
        self::assertTrue($repo->eliminar($id));
        self::assertNull($repo->findById($id));
        self::assertFalse($repo->eliminar($id));
    }

    public function testResumenesDeUsuariosEnUnaConsulta(): void
    {
        $usuarios = $this->mongo()->collection(Collections::USUARIOS);
        $a = new ObjectId();
        $b = new ObjectId();
        $usuarios->insertMany([
            ['_id' => $a, 'nombre' => 'IT', 'apellido' => 'A', 'rol' => 'estudiante', 'estado' => true, 'telefono' => '300', 'credencial_id' => new ObjectId()],
            ['_id' => $b, 'nombre' => 'IT', 'apellido' => 'B', 'rol' => 'estudiante', 'estado' => false, 'credencial_id' => new ObjectId()],
        ]);

        try {
            $resumenes = (new UsuariosRepository($this->mongo()))->findResumenesByIds([$a, $b, new ObjectId()]);

            self::assertEqualsCanonicalizing([(string) $a, (string) $b], array_keys($resumenes));
            self::assertArrayNotHasKey('telefono', $resumenes[(string) $a]);
            self::assertArrayNotHasKey('credencial_id', $resumenes[(string) $a]);
            self::assertFalse($resumenes[(string) $b]['estado']);
            self::assertSame([], (new UsuariosRepository($this->mongo()))->findResumenesByIds([]));
        } finally {
            $usuarios->deleteMany(['_id' => ['$in' => [$a, $b]]]);
        }
    }

    /**
     * @param list<string> $categorias
     */
    private function criterios(
        array $categorias,
        string $alcance,
        ?ObjectId $actor = null,
        ?string $estado = null,
        ?bool $visible = null,
        ?ObjectId $integrante = null,
    ): DocumentoCriterios {
        // Siempre filtra por el autor de la prueba: la base de pruebas puede tener otros documentos.
        return new DocumentoCriterios($categorias, $alcance, $actor, $estado, $visible, $this->autor, $integrante);
    }

    /**
     * @return list<string>
     */
    private function ids(DocumentoCriterios $criterios): array
    {
        $items = (new DocumentosRepository($this->mongo()))->paginar($criterios, 1, 50)['items'];

        return array_map(static fn (array $d): string => (string) $d['_id'], $items);
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function insertar(array $campos): string
    {
        $id = new ObjectId();
        $this->mongo()->collection(Collections::DOCUMENTOS)->insertOne($this->documento($id, $campos));

        return (string) $id;
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function documento(ObjectId $id, array $campos): array
    {
        $fecha = new UTCDateTime(++$this->ms);

        return ['_id' => $id] + $campos + [
            'categoria' => 'proyecto',
            'titulo' => 'IT',
            'descripcion' => 'Prueba de integración',
            'contenido' => 'Contenido IT',
            'autor_id' => $this->autor,
            'integrantes' => [],
            'archivos_adjuntos' => [],
            'estado' => 'en_revision',
            'visible' => false,
            'fecha_creacion' => $fecha,
            'fecha_actualizacion' => $fecha,
        ];
    }
}
