<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Repositories\ResenasRepository;

/**
 * ResenasRepository y MensajesContactoRepository contra Atlas (siiis_test). Los datos llevan un prefijo propio
 * en `nombre_visible`/`nombre` y se borran al terminar. Como la colección puede tener datos de otras pruebas, se
 * comprueban solo los registros propios y el resumen se mide como diferencia.
 */
final class InteraccionRepositoriesIntegrationTest extends IntegrationTestCase
{
    private string $prefijo;

    private int $ms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefijo = 'it-' . bin2hex(random_bytes(5)) . '-';
        // En el futuro para quedar primeros en el orden por fecha descendente.
        $this->ms = (int) (microtime(true) * 1000) + 86_400_000;
    }

    protected function tearDown(): void
    {
        if (self::$mongo === null) {
            return;
        }
        $regex = new Regex('^' . preg_quote($this->prefijo, '/'));
        $this->mongo()->collection(Collections::RESENAS)->deleteMany(['nombre_visible' => $regex]);
        $this->mongo()->collection(Collections::MENSAJES_CONTACTO)->deleteMany(['nombre' => $regex]);
        self::assertSame(0, $this->mongo()->collection(Collections::RESENAS)->countDocuments(['nombre_visible' => $regex]));
        self::assertSame(0, $this->mongo()->collection(Collections::MENSAJES_CONTACTO)->countDocuments(['nombre' => $regex]));
    }

    public function testResenasCicloCompletoEnAtlas(): void
    {
        $repo = new ResenasRepository($this->mongo());
        $antes = $repo->resumenVisibles();

        $oculta = $this->resena($repo, 'oculta', 1);
        $visible5 = $this->resena($repo, 'visible', 5);
        $visible2 = $this->resena($repo, 'visible', 2);
        $sinNota = $this->resena($repo, 'visible', null);

        // Tipos guardados (spec 8.2).
        $doc = $repo->findById($visible5);
        self::assertNotNull($doc);
        self::assertNull($doc['usuario_id']);
        self::assertIsInt($doc['calificacion']);
        self::assertInstanceOf(UTCDateTime::class, $doc['fecha']);

        // Más recientes primero; el filtro de estado se aplica en Mongo.
        $visibles = $repo->paginar('visible', 1, 3)['items'];
        self::assertEquals([$sinNota, $visible2, $visible5], array_column($visibles, '_id'));
        $todas = array_column($repo->paginar(null, 1, 4)['items'], '_id');
        self::assertEquals([$sinNota, $visible2, $visible5, $oculta], $todas);
        $segunda = array_column($repo->paginar(null, 2, 2)['items'], '_id');
        self::assertEquals([$visible5, $oculta], $segunda);
        self::assertNotContains((string) $oculta, self::ids($repo->paginar('visible', 1, 50)['items']));

        // El resumen solo cuenta visibles con calificación: +2 calificaciones (5 y 2).
        $despues = $repo->resumenVisibles();
        self::assertSame($antes['calificaciones'] + 2, $despues['calificaciones']);
        $sumaAntes = ($antes['promedio'] ?? 0.0) * $antes['calificaciones'];
        self::assertEqualsWithDelta(($sumaAntes + 7) / $despues['calificaciones'], $despues['promedio'], 0.0001);

        // Moderación.
        self::assertTrue($repo->cambiarEstado($oculta, 'visible'));
        self::assertSame('visible', $repo->findById($oculta)['estado'] ?? null);
        self::assertFalse($repo->cambiarEstado(new ObjectId(), 'visible'));
        self::assertTrue($repo->eliminar($oculta));
        self::assertNull($repo->findById($oculta));
        self::assertFalse($repo->eliminar($oculta));
    }

    public function testMensajesDeContactoEnAtlas(): void
    {
        $repo = new MensajesContactoRepository($this->mongo());
        $primero = $this->mensaje($repo, 'no_leido');
        $segundo = $this->mensaje($repo, 'leido');

        $doc = $repo->findById($primero);
        self::assertNotNull($doc);
        self::assertSame(['_id', 'nombre', 'correo', 'asunto', 'mensaje', 'estado', 'fecha'], array_keys($doc));
        self::assertInstanceOf(UTCDateTime::class, $doc['fecha']);

        self::assertEquals([$segundo, $primero], array_column($repo->paginar(null, 1, 2)['items'], '_id'));
        self::assertNotContains((string) $segundo, self::ids($repo->paginar('no_leido', 1, 50)['items']));
        self::assertContains((string) $segundo, self::ids($repo->paginar('leido', 1, 50)['items']));
        self::assertContains((string) $primero, self::ids($repo->paginar('no_leido', 1, 50)['items']));

        self::assertTrue($repo->cambiarEstado($primero, 'leido'));
        self::assertSame('leido', $repo->findById($primero)['estado'] ?? null);
        self::assertFalse($repo->cambiarEstado(new ObjectId(), 'leido'));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn (array $d): string => (string) $d['_id'], $items);
    }

    private function resena(ResenasRepository $repo, string $estado, ?int $calificacion): ObjectId
    {
        $id = new ObjectId();
        $repo->insertar([
            '_id' => $id,
            'usuario_id' => null,
            'nombre_visible' => $this->prefijo . 'visitante',
            'comentario' => 'Reseña de integración',
            'calificacion' => $calificacion,
            'estado' => $estado,
            'fecha' => new UTCDateTime(++$this->ms),
        ]);

        return $id;
    }

    private function mensaje(MensajesContactoRepository $repo, string $estado): ObjectId
    {
        $id = new ObjectId();
        $repo->insertar([
            '_id' => $id,
            'nombre' => $this->prefijo . 'remitente',
            'correo' => 'it@example.test',
            'asunto' => 'Asunto de integración',
            'mensaje' => 'Mensaje de integración',
            'estado' => $estado,
            'fecha' => new UTCDateTime(++$this->ms),
        ]);

        return $id;
    }
}
