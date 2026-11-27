<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Repositories\ResenasRepository;
use Siiis\Scripts\Demo\DemoArchivos;
use Siiis\Security\Roles;
use Siiis\Support\ModuloDocumentos;
use Siiis\Tests\Support\TestSettings;
use Siiis\Validation\FileValidator;

require_once dirname(__DIR__, 3) . '/scripts/demo/DemoArchivos.php';

/**
 * Archivos generados y datos de scripts/seed_demo.php: deben pasar las mismas reglas que lo que entra por la API.
 */
final class SeedDemoTest extends TestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function datos(): array
    {
        /** @var array<string, mixed> */
        return require dirname(__DIR__, 3) . '/scripts/demo/datos.php';
    }

    private function temporal(string $contenido, string $extension): string
    {
        return $this->temporales[] = DemoArchivos::temporal($contenido, $extension);
    }

    public function testPngValidoConLasDimensionesPedidas(): void
    {
        $ruta = $this->temporal(DemoArchivos::png(64, 36, [0, 0, 0], [255, 255, 255], 1), 'png');

        self::assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->file($ruta));
        $info = getimagesize($ruta);
        self::assertIsArray($info);
        self::assertSame([64, 36], [$info[0], $info[1]]);
        self::assertSame(
            DemoArchivos::png(64, 36, [0, 0, 0], [255, 255, 255], 1),
            DemoArchivos::png(64, 36, [0, 0, 0], [255, 255, 255], 1),
            'la misma semilla genera la misma imagen',
        );
    }

    public function testPdfValidoConTildesYCaracteresEscapados(): void
    {
        $pdf = DemoArchivos::pdf('Título (demo)', ['Programación \\ ñandú']);
        $ruta = $this->temporal($pdf, 'pdf');

        self::assertSame(FileValidator::MIME_PDF, (new \finfo(FILEINFO_MIME_TYPE))->file($ruta));
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertStringContainsString('(T' . "\xED" . 'tulo \\(demo\\)) Tj', $pdf);
        self::assertStringContainsString('\\\\', $pdf);
        // La tabla xref apunta al inicio de cada objeto.
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        self::assertStringStartsWith('xref', substr($pdf, (int) $m[1]));
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
        foreach ($offsets[1] as $n => $offset) {
            self::assertStringStartsWith(($n + 1) . ' 0 obj', substr($pdf, (int) $offset));
        }
    }

    public function testUsuariosConRolesValidosCorreosDeDemoYUnoDeCadaRol(): void
    {
        /** @var array<string, array<string, mixed>> $usuarios */
        $usuarios = self::datos()['usuarios'];

        $correos = array_column($usuarios, 'correo');
        self::assertSame(count($correos), count(array_unique($correos)));
        foreach ($usuarios as $u) {
            self::assertMatchesRegularExpression('/^demo\.[^@]+@example\.com$/', (string) $u['correo']);
            self::assertContains($u['rol'], Roles::TODOS);
            self::assertIsBool($u['activo']);
        }
        $activos = array_filter($usuarios, static fn (array $u): bool => $u['activo'] === true);
        self::assertEqualsCanonicalizing(Roles::TODOS, array_values(array_unique(array_column($activos, 'rol'))));
    }

    public function testDocumentosConEstadosPorCategoriaYReferenciasExistentes(): void
    {
        $datos = self::datos();
        /** @var array<string, mixed> $usuarios */
        $usuarios = $datos['usuarios'];
        /** @var list<array<string, mixed>> $documentos */
        $documentos = $datos['documentos'];
        $s = TestSettings::make();

        $porCategoria = array_count_values(array_column($documentos, 'categoria'));
        self::assertSame(6, $porCategoria['proyecto']);
        self::assertSame(6, $porCategoria['articulo'] + $porCategoria['noticia']);
        foreach ($documentos as $d) {
            self::assertContains($d['estado'], ModuloDocumentos::estadosDe((string) $d['categoria']));
            self::assertArrayHasKey($d['autor'], $usuarios);
            self::assertIsArray($d['integrantes']);
            foreach ($d['integrantes'] as $integrante) {
                self::assertArrayHasKey($integrante, $usuarios);
            }
            self::assertLessThanOrEqual($s->documentoTituloMaxLength, mb_strlen((string) $d['titulo']));
            self::assertLessThanOrEqual($s->documentoDescripcionMaxLength, mb_strlen((string) $d['descripcion']));
            self::assertFalse($d['estado'] === 'en_revision' && $d['visible'] === true, 'un borrador no es visible');
        }
    }

    public function testResenasMensajesEInscripcionesCumplenLasReglas(): void
    {
        $datos = self::datos();
        $s = TestSettings::make();
        /** @var array<string, mixed> $usuarios */
        $usuarios = $datos['usuarios'];

        /** @var list<array<string, mixed>> $resenas */
        $resenas = $datos['resenas'];
        self::assertCount(10, $resenas);
        $comentarios = array_column($resenas, 'comentario');
        self::assertSame(count($comentarios), count(array_unique($comentarios)), 'el comentario identifica la reseña');
        foreach ($resenas as $r) {
            self::assertContains($r['estado'], ResenasRepository::ESTADOS);
            self::assertTrue($r['calificacion'] === null || ($r['calificacion'] >= 1 && $r['calificacion'] <= 5));
            self::assertTrue($r['usuario'] === null ? is_string($r['nombre_visible']) : isset($usuarios[$r['usuario']]));
            self::assertLessThanOrEqual($s->resenaComentarioMaxLength, mb_strlen((string) $r['comentario']));
        }

        /** @var list<array<string, mixed>> $mensajes */
        $mensajes = $datos['mensajes'];
        self::assertCount(5, $mensajes);
        foreach ($mensajes as $m) {
            self::assertContains($m['estado'], MensajesContactoRepository::ESTADOS);
            self::assertMatchesRegularExpression('/^demo\.[^@]+@example\.com$/', (string) $m['correo']);
        }

        /** @var list<array<string, mixed>> $inscripciones */
        $inscripciones = $datos['inscripciones'];
        self::assertCount(3, $inscripciones);
        foreach ($inscripciones as $i) {
            self::assertMatchesRegularExpression('/^demo\.[^@]+@example\.com$/', (string) $i['correo']);
            self::assertGreaterThanOrEqual($s->inscripcionCartaMinLength, mb_strlen((string) $i['carta_motivacion']));
            self::assertLessThanOrEqual($s->inscripcionCartaMaxLength, mb_strlen((string) $i['carta_motivacion']));
            self::assertIsArray($i['documentos']);
            self::assertNotEmpty($i['documentos']);
            self::assertLessThanOrEqual($s->inscripcionDocumentosMax, count($i['documentos']));
            self::assertLessThanOrEqual($s->perfilSemestreMax, $i['semestre']);
        }
    }

    public function testMultimediaTresImagenesPorSeccionYUnVideo(): void
    {
        $datos = self::datos();
        /** @var list<array<string, mixed>> $imagenes */
        $imagenes = $datos['imagenes'];

        self::assertSame(
            ['galeria' => 3, 'slider_principal' => 3],
            (static function (array $c): array {
                ksort($c);

                return $c;
            })(array_count_values(array_column($imagenes, 'seccion'))),
        );
        $descripciones = array_column($imagenes, 'descripcion');
        self::assertSame(count($descripciones), count(array_unique($descripciones)));
        self::assertIsArray($datos['video']);
        self::assertSame('video_presentacion', $datos['video']['seccion']);
    }
}
