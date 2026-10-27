<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use MongoDB\BSON\ObjectId;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Services\UsersService;
use Siiis\Support\Logger;
use Siiis\Support\SystemClock;
use Siiis\Tests\Support\TestImages;

/**
 * Cloudinary real + MONGO_DB_NAME_TEST. Sube solo a la carpeta de pruebas y la vacía al terminar.
 * Se salta sin credenciales CLOUDINARY_*.
 */
final class CloudinaryIntegrationTest extends IntegrationTestCase
{
    /** Nunca la carpeta de producción (siiis/perfiles). */
    private const FOLDER = 'siiis_test/perfiles';

    /** @var list<ObjectId> */
    private array $usuariosCreados = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::configurado($this->settings())) {
            self::markTestSkipped('CLOUDINARY_* no configuradas: se omiten las pruebas con Cloudinary.');
        }
    }

    private static function configurado(Settings $s): bool
    {
        return $s->cloudinaryCloudName !== '' && $s->cloudinaryApiKey !== '' && $s->cloudinaryApiSecret !== '';
    }

    protected function tearDown(): void
    {
        foreach ($this->usuariosCreados as $id) {
            $this->mongo()->collection(Collections::USUARIOS)->deleteOne(['_id' => $id]);
        }
        $this->usuariosCreados = [];
        TestImages::limpiar();
    }

    public static function tearDownAfterClass(): void
    {
        $settings = self::$settings;
        if ($settings !== null && self::configurado($settings)) {
            // Red de seguridad: borra cualquier resto de la carpeta de pruebas.
            self::admin($settings)->adminApi()->deleteAssetsByPrefix(self::FOLDER . '/', ['invalidate' => true]);
        }
        parent::tearDownAfterClass();
    }

    public function testSubirDescargarYBorrar(): void
    {
        $service = new CloudinaryService($this->settings());

        $foto = $service->upload(TestImages::png(), self::FOLDER);

        self::assertStringStartsWith('https://res.cloudinary.com/' . $this->settings()->cloudinaryCloudName . '/', $foto['url']);
        self::assertStringStartsWith(self::FOLDER . '/', $foto['public_id']);
        $descarga = @file_get_contents($foto['url']);
        self::assertIsString($descarga, 'La URL entregada no se pudo descargar.');
        self::assertStringStartsWith("\x89PNG", $descarga);

        $service->delete($foto['public_id']);
        self::assertFalse($this->existe($foto['public_id']));
        // Borrar algo que ya no existe no es error.
        $service->delete($foto['public_id']);
    }

    public function testCambiarFotoReemplazaYBorraLaAnterior(): void
    {
        $settings = Settings::fromEnv(['CLOUDINARY_PERFILES_FOLDER' => self::FOLDER] + $_ENV + getenv() + [
            'JWT_SECRET' => str_repeat('x', 32),
        ], $this->settings()->storagePath);
        $cloudinary = new CloudinaryService($settings);
        $usuarios = new UsuariosRepository($this->mongo());
        $service = new UsersService(
            $usuarios,
            new CredencialesRepository($this->mongo()),
            new SesionesRepository($this->mongo()),
            new SystemClock(),
            $cloudinary,
            $settings,
            new Logger($settings->storagePath . '/logs'),
        );

        $id = new ObjectId();
        $this->mongo()->collection(Collections::USUARIOS)->insertOne([
            '_id' => $id, 'nombre' => 'IT', 'apellido' => 'Foto', 'rol' => 'estudiante', 'estado' => true,
            'foto_perfil' => null,
        ]);
        $this->usuariosCreados[] = $id;

        $primera = $service->cambiarFoto((string) $id, (string) $id, 'estudiante', TestImages::png());
        $guardada = $usuarios->findPerfilById($id)['foto_perfil'] ?? null;
        self::assertIsArray($guardada);
        self::assertStringStartsWith(self::FOLDER . '/', $guardada['public_id']);
        self::assertSame(['url' => $guardada['url']], $primera['foto_perfil']);
        self::assertTrue($this->existe($guardada['public_id']));

        $service->cambiarFoto((string) $id, (string) $id, 'estudiante', TestImages::png());
        $nueva = $usuarios->findPerfilById($id)['foto_perfil'] ?? null;
        self::assertIsArray($nueva);
        self::assertNotSame($guardada['public_id'], $nueva['public_id']);
        self::assertTrue($this->existe($nueva['public_id']));
        self::assertFalse($this->existe($guardada['public_id']), 'La foto anterior debió borrarse.');

        $cloudinary->delete($nueva['public_id']);
    }

    private function existe(string $publicId): bool
    {
        try {
            self::admin($this->settings())->adminApi()->asset($publicId);

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
