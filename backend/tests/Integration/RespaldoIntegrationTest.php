<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\RespaldoRepository;
use Siiis\Services\RespaldoService;
use Siiis\Tests\Support\FixedClock;

/**
 * scripts/respaldar_base.php (auditoría A-01) contra MONGO_DB_NAME_TEST: el respaldo conserva los tipos BSON
 * exactos, deja un manifest, no deja carpetas parciales y aplica la retención.
 */
final class RespaldoIntegrationTest extends IntegrationTestCase
{
    private string $coleccion = '';
    private string $directorio = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->coleccion = 'it_respaldo_' . bin2hex(random_bytes(4));
        $this->directorio = sys_get_temp_dir() . '/siiis-respaldo-it-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->coleccion !== '' && self::$mongo !== null) {
            self::$mongo->collection($this->coleccion)->drop();
        }
        self::borrar($this->directorio);
        parent::tearDown();
    }

    public function testRespaldoConservaTiposYAplicaRetencion(): void
    {
        $documentos = [
            [
                '_id' => new ObjectId(),
                'titulo' => 'Reseña con tildes y ñ',
                'fecha' => new UTCDateTime(1795000000123),
                'grande' => new Int64('9007199254740993'),
                'pequeno' => 7,
                'decimal' => new Decimal128('12.50'),
                'lista' => [1, 'dos', ['tres' => true]],
                'vacio' => new \stdClass(),
                'nulo' => null,
            ],
            ['_id' => new ObjectId(), 'titulo' => 'Segundo'],
        ];
        $this->mongo()->collection($this->coleccion)->insertMany($documentos);

        $clock = new FixedClock('2026-11-28T03:00:00Z');
        $servicio = new RespaldoService(new RespaldoRepository($this->mongo()), $clock, $this->directorio, 2);

        $resultado = $servicio->crear();

        self::assertSame($this->directorio . '/20261128-030000', $resultado['carpeta']);
        self::assertSame(2, $resultado['colecciones'][$this->coleccion]);
        self::assertSame([], $resultado['borrados']);

        // Cada línea es Extended JSON canónico y vuelve a dar exactamente el documento de la base.
        $lineas = file($resultado['carpeta'] . '/' . $this->coleccion . '.json', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lineas);
        self::assertCount(2, $lineas);
        $originales = iterator_to_array((new RespaldoRepository($this->mongo()))->documentos($this->coleccion), false);
        foreach ($lineas as $i => $linea) {
            self::assertSame($originales[$i]->toCanonicalExtendedJSON(), Document::fromJSON($linea)->toCanonicalExtendedJSON());
        }
        self::assertStringContainsString('"$numberLong" : "9007199254740993"', $lineas[0]);
        self::assertStringContainsString('"$numberDecimal" : "12.50"', $lineas[0]);
        self::assertStringContainsString('"$oid"', $lineas[0]);

        $manifest = json_decode((string) file_get_contents($resultado['carpeta'] . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame($this->mongo()->databaseName(), $manifest['base']);
        self::assertSame(2, $manifest['colecciones'][$this->coleccion]);
        self::assertFileExists($this->directorio . '/.htaccess');

        // Retención: con máximo 2, el tercer respaldo borra el primero. Dos en el mismo segundo no chocan.
        $segundo = $servicio->crear();
        self::assertSame($this->directorio . '/20261128-030000-2', $segundo['carpeta']);
        $clock->advance(60);
        $tercero = $servicio->crear();

        self::assertSame([$this->directorio . '/20261128-030000'], $tercero['borrados']);
        self::assertSame([$tercero['carpeta'], $segundo['carpeta']], $servicio->respaldos());
        self::assertSame([], glob($this->directorio . '/.parcial-*') ?: []);
    }

    private static function borrar(string $ruta): void
    {
        if ($ruta === '' || !file_exists($ruta)) {
            return;
        }
        if (is_dir($ruta)) {
            foreach (scandir($ruta) ?: [] as $entrada) {
                if ($entrada !== '.' && $entrada !== '..') {
                    self::borrar($ruta . '/' . $entrada);
                }
            }
            @rmdir($ruta);

            return;
        }
        @unlink($ruta);
    }
}
