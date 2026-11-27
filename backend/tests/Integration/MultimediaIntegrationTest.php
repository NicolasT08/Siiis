<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Services\MultimediaService;
use Siiis\Support\Logger;
use Siiis\Support\SystemClock;
use Siiis\Tests\Support\TestImages;

/**
 * Administración del inicio (DEC-B62) con Cloudinary real (carpeta siiis_test/home) y MONGO_DB_NAME_TEST.
 * Sube una foto grande y un video, comprueba la reducción a 1600 px y que borrar quita el archivo de Cloudinary.
 */
final class MultimediaIntegrationTest extends IntegrationTestCase
{
    private const FOLDER = 'siiis_test/home';

    /** Video público de muestra de Cloudinary (cloud "demo"), recortado a 2 s: Cloudinary lo descarga directamente. */
    private const VIDEO_MUESTRA = 'https://res.cloudinary.com/demo/video/upload/du_2,w_160/dog.mp4';

    private Settings $s;

    protected function setUp(): void
    {
        parent::setUp();
        $base = $this->settings();
        if ($base->cloudinaryCloudName === '' || $base->cloudinaryApiKey === '' || $base->cloudinaryApiSecret === '') {
            self::markTestSkipped('CLOUDINARY_* no configuradas: se omiten las pruebas con Cloudinary.');
        }
        $this->s = Settings::fromEnv(
            ['CLOUDINARY_HOME_FOLDER' => self::FOLDER, 'MONGO_DB_NAME' => $base->mongoDbName, 'APP_ENV' => 'testing'] + $_ENV + getenv() + ['JWT_SECRET' => str_repeat('x', 32)],
            $base->storagePath,
        );
    }

    protected function tearDown(): void
    {
        $this->mongo()->collection(Collections::MULTIMEDIA)->deleteMany(['public_id' => ['$regex' => '^' . self::FOLDER . '/']]);
        TestImages::limpiar();
    }

    public static function tearDownAfterClass(): void
    {
        $s = self::$settings;
        if ($s !== null && $s->cloudinaryCloudName !== '') {
            foreach (['image', 'video'] as $tipo) {
                self::admin($s)->adminApi()->deleteAssetsByPrefix(self::FOLDER . '/', ['resource_type' => $tipo, 'invalidate' => true]);
            }
        }
        parent::tearDownAfterClass();
    }

    private function servicio(): MultimediaService
    {
        return new MultimediaService(
            new MultimediaRepository($this->mongo()),
            new CloudinaryService($this->s),
            $this->s,
            new SystemClock(),
            new Logger($this->s->storagePath . '/logs'),
        );
    }

    public function testSubirFotoReducidaVideoReordenarYBorrar(): void
    {
        $servicio = $this->servicio();

        $foto = $servicio->crear(
            ['seccion' => 'slider_principal', 'descripcion' => 'Foto grande de prueba', 'orden' => null],
            ['path' => TestImages::pngDeDimensiones(2400, 100), 'mime' => 'image/png', 'size' => 1],
        );
        $video = $servicio->crear(
            ['seccion' => 'video_presentacion', 'descripcion' => 'Video de prueba', 'orden' => null],
            ['path' => self::VIDEO_MUESTRA, 'mime' => 'video/mp4', 'size' => 1],
        );

        self::assertStringContainsString('/image/upload/f_auto,q_auto/', $foto['url']);
        self::assertStringContainsString('/video/upload/', $video['url']);
        $docFoto = $this->mongo()->collection(Collections::MULTIMEDIA)->findOne(['seccion' => 'slider_principal', 'descripcion' => 'Foto grande de prueba']);
        self::assertIsArray($docFoto);
        self::assertStringStartsWith(self::FOLDER . '/slider/', (string) $docFoto['public_id']);
        $recurso = self::admin($this->s)->adminApi()->asset((string) $docFoto['public_id']);
        self::assertSame(1600, $recurso['width'], 'La foto se guarda reducida a 1600 px de ancho.');

        // Aparece en la ruta pública (misma colección) y se puede reordenar.
        $repo = new MultimediaRepository($this->mongo());
        self::assertContains('Foto grande de prueba', array_column($repo->findBySeccion('slider_principal', 50), 'descripcion'));
        $actualizado = $servicio->actualizar($foto['id'], ['descripcion' => 'Descripción editada', 'orden' => 9]);
        self::assertSame(['Descripción editada', 9], [$actualizado['descripcion'], $actualizado['orden']]);

        // Borrar quita el registro y el archivo de Cloudinary (también el video, como recurso de video).
        $docVideo = $this->mongo()->collection(Collections::MULTIMEDIA)->findOne(['seccion' => 'video_presentacion', 'descripcion' => 'Video de prueba']);
        self::assertIsArray($docVideo);
        $servicio->eliminar($foto['id']);
        $servicio->eliminar($video['id']);
        self::assertFalse($this->existe((string) $docFoto['public_id'], 'image'));
        self::assertFalse($this->existe((string) $docVideo['public_id'], 'video'));
        self::assertNull($repo->findById($docFoto['_id']));
    }

    private function existe(string $publicId, string $tipo): bool
    {
        try {
            self::admin($this->s)->adminApi()->asset($publicId, ['resource_type' => $tipo]);

            return true;
        } catch (NotFound) {
            return false;
        }
    }

    private static function admin(Settings $s): Cloudinary
    {
        return new Cloudinary(['cloud' => [
            'cloud_name' => $s->cloudinaryCloudName,
            'api_key' => $s->cloudinaryApiKey,
            'api_secret' => $s->cloudinaryApiSecret,
        ]]);
    }
}
