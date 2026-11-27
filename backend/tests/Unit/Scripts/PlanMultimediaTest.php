<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Siiis\Scripts\Multimedia\PlanMultimedia;
use Siiis\Tests\Support\TestImages;

require_once dirname(__DIR__, 3) . '/scripts/multimedia/PlanMultimedia.php';

final class PlanMultimediaTest extends TestCase
{
    private string $carpeta;

    /** Cabecera `ftyp` de un MP4: suficiente para que finfo lo reconozca como video/mp4. */
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/siiis-multimedia-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta . '/slider', 0777, true);
        mkdir($this->carpeta . '/videos', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['slider', 'videos'] as $sub) {
            array_map('unlink', glob($this->carpeta . "/$sub/*") ?: []);
            @rmdir($this->carpeta . "/$sub");
        }
        array_map('unlink', glob($this->carpeta . '/*.json') ?: []);
        @rmdir($this->carpeta);
        TestImages::limpiar();
    }

    private function poner(string $ruta, string $origenOBytes, bool $esRuta = true): void
    {
        file_put_contents($this->carpeta . '/' . $ruta, $esRuta ? (string) file_get_contents($origenOBytes) : $origenOBytes);
    }

    public function testArmaElPlanConOrdenNaturalDescripcionesYPublicIdEstable(): void
    {
        $this->poner('slider/10-Cierre del año.png', TestImages::png());
        $this->poner('slider/2_laboratorio-de_redes.webp', TestImages::webp());
        $this->poner('slider/01-Inauguración.jpg', TestImages::jpeg());
        $this->poner('videos/01-presentacion.mp4', self::MP4, false);
        $this->poner('descripciones.json', '{"01-Inauguración.jpg": "Inauguración del semillero en 2024"}', false);

        $plan = PlanMultimedia::desdeCarpeta($this->carpeta, 'siiis/home');

        self::assertSame([], $plan['errores']);
        $slider = array_values(array_filter($plan['items'], static fn (array $i): bool => $i['seccion'] === 'slider_principal'));
        self::assertSame(['slider/01-Inauguración.jpg', 'slider/2_laboratorio-de_redes.webp', 'slider/10-Cierre del año.png'], array_column($slider, 'archivo'));
        self::assertSame([1, 2, 3], array_column($slider, 'orden'));
        self::assertSame(['siiis/home/slider/inauguracion', 'siiis/home/slider/laboratorio-de-redes', 'siiis/home/slider/cierre-del-ano'], array_column($slider, 'public_id'));
        self::assertSame(['Inauguración del semillero en 2024', 'Laboratorio de redes', 'Cierre del año'], array_column($slider, 'descripcion'));
        self::assertSame('imagen', $slider[0]['tipo']);

        $videos = array_values(array_filter($plan['items'], static fn (array $i): bool => $i['seccion'] === 'video_presentacion'));
        self::assertCount(1, $videos);
        self::assertSame(['video', 'siiis/home/videos/presentacion', 1], [$videos[0]['tipo'], $videos[0]['public_id'], $videos[0]['orden']]);
    }

    public function testExistentesDesdeJson(): void
    {
        $this->poner('existentes.json', (string) json_encode([
            'slider' => [
                ['public_id' => 'siiis/Foto_1', 'descripcion' => "Integrantes\n junto al mural"],
                ['public_id' => 'siiis/Foto_2', 'descripcion' => 'Charla', 'orden' => 5],
            ],
            'videos' => [['public_id' => 'siiis/presentacion', 'descripcion' => 'Video del semillero']],
        ]), false);

        $plan = PlanMultimedia::desdeJson($this->carpeta . '/existentes.json');

        self::assertSame([], $plan['errores']);
        self::assertSame([
            ['seccion' => 'slider_principal', 'tipo' => 'imagen', 'public_id' => 'siiis/Foto_1', 'orden' => 1, 'descripcion' => 'Integrantes junto al mural'],
            ['seccion' => 'slider_principal', 'tipo' => 'imagen', 'public_id' => 'siiis/Foto_2', 'orden' => 5, 'descripcion' => 'Charla'],
            ['seccion' => 'video_presentacion', 'tipo' => 'video', 'public_id' => 'siiis/presentacion', 'orden' => 1, 'descripcion' => 'Video del semillero'],
        ], $plan['items']);
    }

    public function testExistentesRechazaElementosInvalidos(): void
    {
        $this->poner('malo.json', (string) json_encode([
            'slider' => [
                ['public_id' => 'siiis/ok', 'descripcion' => 'Bien'],
                ['public_id' => '', 'descripcion' => 'Sin id'],
                ['public_id' => '../fuera', 'descripcion' => 'Ruta rara'],
                ['public_id' => 'siiis/sin-descripcion'],
                ['public_id' => 'siiis/ok', 'descripcion' => 'Repetido'],
                ['public_id' => 'siiis/orden-malo', 'descripcion' => 'x', 'orden' => '2'],
            ],
            'galeria' => [],
        ]), false);

        $plan = PlanMultimedia::desdeJson($this->carpeta . '/malo.json');

        self::assertSame(['siiis/ok'], array_column($plan['items'], 'public_id'));
        self::assertCount(6, $plan['errores']);
        self::assertNotSame([], PlanMultimedia::desdeJson($this->carpeta . '/no-existe.json')['errores']);
        $this->poner('vacio.json', '{"slider": [], "videos": []}', false);
        self::assertNotSame([], PlanMultimedia::desdeJson($this->carpeta . '/vacio.json')['errores']);
    }

    public function testRenombrarElPrefijoNoCambiaElPublicId(): void
    {
        self::assertSame(PlanMultimedia::slug('01-lab.jpg'), PlanMultimedia::slug('07_lab.jpg'));
    }

    public function testRechazaFormatosEquivocadosNombresRepetidosYCarpetasSinEstructura(): void
    {
        $this->poner('slider/01-notas.txt', 'no soy una imagen', false);
        $this->poner('slider/02-foto.png', TestImages::png());
        $this->poner('slider/03-foto.webp', TestImages::webp());
        $this->poner('videos/01-foto.png', TestImages::png());

        $plan = PlanMultimedia::desdeCarpeta($this->carpeta, 'siiis/home');

        self::assertSame(['slider/02-foto.png'], array_column($plan['items'], 'archivo'));
        self::assertCount(3, $plan['errores']);
        self::assertStringContainsString('01-notas.txt', $plan['errores'][0]);
        self::assertStringContainsString('se repite', $plan['errores'][1]);
        self::assertStringContainsString('MP4, WebM o MOV', $plan['errores'][2]);

        self::assertNotSame([], PlanMultimedia::desdeCarpeta($this->carpeta . '/slider', 'x')['errores']);
        self::assertNotSame([], PlanMultimedia::desdeCarpeta($this->carpeta . '/no-existe', 'x')['errores']);
    }
}
