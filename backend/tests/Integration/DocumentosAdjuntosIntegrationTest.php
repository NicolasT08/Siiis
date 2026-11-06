<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Repositories\DocumentosRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Services\DocumentosService;
use Siiis\Support\Logger;
use Siiis\Support\ModuloDocumentos;
use Siiis\Support\SystemClock;
use Siiis\Tests\Support\TestImages;

/**
 * Adjuntos con Cloudinary real + MONGO_DB_NAME_TEST (DEC-B39, DEC-B40). Sube solo a siiis_test/documentos y
 * la vacía al terminar. Comprueba que el PDF y la imagen se pueden descargar: si el PDF responde 401 "deny or
 * ACL failure", la cuenta no tiene activado "Allow delivery of PDF and ZIP files" (Settings > Security).
 * Se salta sin credenciales CLOUDINARY_*.
 */
final class DocumentosAdjuntosIntegrationTest extends IntegrationTestCase
{
    /** Nunca la carpeta de producción (siiis/documentos). */
    private const FOLDER = 'siiis_test/documentos';

    private ObjectId $autor;

    private DocumentosService $service;

    private DocumentosRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::configurado($this->settings())) {
            self::markTestSkipped('CLOUDINARY_* no configuradas: se omiten las pruebas con Cloudinary.');
        }

        $settings = Settings::fromEnv(['CLOUDINARY_DOCUMENTOS_FOLDER' => self::FOLDER] + $_ENV + getenv() + [
            'JWT_SECRET' => str_repeat('x', 32),
        ], $this->settings()->storagePath);
        $this->repo = new DocumentosRepository($this->mongo());
        $this->service = new DocumentosService(
            $this->repo,
            new UsuariosRepository($this->mongo()),
            new SystemClock(),
            new CloudinaryService($settings),
            $settings,
            new Logger($settings->storagePath . '/logs'),
        );
        $this->autor = new ObjectId();
    }

    protected function tearDown(): void
    {
        if (self::$mongo !== null) {
            $this->mongo()->collection(Collections::DOCUMENTOS)->deleteMany(['autor_id' => $this->autor]);
        }
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

    public function testSubirYQuitarUnPdf(): void
    {
        $id = $this->crearDocumento();

        $doc = $this->service->agregarAdjunto(
            ModuloDocumentos::PROYECTOS,
            (string) $id,
            ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'nombre' => 'informe.pdf'],
            (string) $this->autor,
            'estudiante',
        );

        self::assertCount(1, $doc['archivos_adjuntos']);
        $adjunto = $doc['archivos_adjuntos'][0];
        self::assertSame('informe.pdf', $adjunto['nombre_archivo']);
        self::assertStringStartsWith('https://res.cloudinary.com/' . $this->settings()->cloudinaryCloudName . '/image/upload/', $adjunto['url']);
        self::assertStringEndsWith('.pdf', $adjunto['url']);

        // El PDF no lleva f_auto,q_auto ni transformación al subir: se guarda tal cual (DEC-B42).
        self::assertStringNotContainsString('f_auto', $adjunto['url']);

        $publicId = $this->repo->findById($id)['archivos_adjuntos'][0]['public_id'];
        self::assertStringStartsWith(self::FOLDER . '/', $publicId);
        self::assertTrue($this->existe($publicId));
        $asset = self::admin($this->settings())->adminApi()->asset($publicId);
        self::assertSame('pdf', $asset['format']);

        $sin = $this->service->quitarAdjunto(ModuloDocumentos::PROYECTOS, (string) $id, $adjunto['archivo'], (string) $this->autor, 'estudiante');
        self::assertSame([], $sin['archivos_adjuntos']);
        self::assertSame([], $this->repo->findById($id)['archivos_adjuntos']);
        self::assertFalse($this->existe($publicId), 'El archivo debió borrarse de Cloudinary.');
    }

    /**
     * La entrega de PDF depende de un ajuste de la cuenta de Cloudinary (DEC-B40). Si la cuenta la bloquea
     * (401 "deny or ACL failure") la prueba se omite con el aviso; cualquier otro fallo la hace fallar.
     */
    public function testElPdfSePuedeDescargar(): void
    {
        $id = $this->crearDocumento();
        $doc = $this->service->agregarAdjunto(
            ModuloDocumentos::PROYECTOS,
            (string) $id,
            ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'nombre' => 'informe.pdf'],
            (string) $this->autor,
            'estudiante',
        );
        $url = $doc['archivos_adjuntos'][0]['url'];

        try {
            [$status, $cuerpo, $errorCloudinary] = self::descargar($url);
        } finally {
            $this->service->eliminar(ModuloDocumentos::PROYECTOS, (string) $id, (string) $this->autor, 'estudiante');
        }

        if ($status === 401 && $errorCloudinary === 'deny or ACL failure') {
            self::markTestSkipped(
                'La cuenta de Cloudinary bloquea la entrega de PDF: active "Allow delivery of PDF and ZIP files" '
                . '(Settings > Security) y vuelva a correr la prueba. Ver docs/deployment.md.',
            );
        }
        self::assertSame(200, $status, 'Error de Cloudinary: ' . $errorCloudinary);
        self::assertStringStartsWith('%PDF', $cuerpo);
    }

    /**
     * DEC-B42: Cloudinary guarda las imágenes adjuntas con ancho máximo 1600 px, sin agrandar las pequeñas, y
     * la API las entrega con f_auto,q_auto.
     */
    public function testLasImagenesSeReducenSinAgrandar(): void
    {
        $id = $this->crearDocumento();
        $agregar = fn (string $path): array => $this->service->agregarAdjunto(
            ModuloDocumentos::PROYECTOS,
            (string) $id,
            ['path' => $path, 'mime' => 'image/png', 'nombre' => 'imagen.png'],
            (string) $this->autor,
            'estudiante',
        );

        $agregar(TestImages::pngDeDimensiones(2000, 40));
        $doc = $agregar(TestImages::pngDeDimensiones(300, 20));

        try {
            $guardados = $this->repo->findById($id)['archivos_adjuntos'];
            self::assertSame([1600, 32], $this->dimensiones($guardados[0]['public_id']), 'Debió reducirse a 1600 px de ancho.');
            self::assertSame([300, 20], $this->dimensiones($guardados[1]['public_id']), 'No debió agrandarse.');

            $url = $doc['archivos_adjuntos'][1]['url'];
            self::assertStringContainsString('/image/upload/f_auto,q_auto/', $url);
            [$status, $cuerpo] = self::descargar($url);
            self::assertSame(200, $status);
            self::assertNotSame('', $cuerpo);
        } finally {
            $this->service->eliminar(ModuloDocumentos::PROYECTOS, (string) $id, (string) $this->autor, 'estudiante');
        }
    }

    public function testLimiteAtomicoYBorradoDelDocumento(): void
    {
        $id = $this->crearDocumento();
        $fecha = new UTCDateTime();
        $adjunto = ['url' => 'https://example.test/x.pdf', 'public_id' => 'it/no-existe', 'nombre_archivo' => 'x.pdf'];

        // El filtro de cupo de MongoDB: con máximo 1, el segundo no entra.
        self::assertTrue($this->repo->agregarAdjunto($id, $adjunto, 1, $fecha));
        self::assertFalse($this->repo->agregarAdjunto($id, $adjunto, 1, $fecha));
        self::assertFalse($this->repo->agregarAdjunto($id, $adjunto, 5, $fecha, 'ejecucion'), 'exige el estado');
        self::assertTrue($this->repo->quitarAdjunto($id, 'it/no-existe', $fecha));
        self::assertFalse($this->repo->quitarAdjunto($id, 'it/no-existe', $fecha));

        $this->service->agregarAdjunto(
            ModuloDocumentos::PROYECTOS,
            (string) $id,
            ['path' => TestImages::png(), 'mime' => 'image/png', 'nombre' => 'foto.png'],
            (string) $this->autor,
            'estudiante',
        );
        $guardado = $this->repo->findById($id)['archivos_adjuntos'][0];
        $publicId = $guardado['public_id'];
        self::assertStringContainsString('/image/upload/', $guardado['url']);
        self::assertStringStartsWith("\x89PNG", (string) @file_get_contents($guardado['url']));
        self::assertTrue($this->existe($publicId));

        $this->service->eliminar(ModuloDocumentos::PROYECTOS, (string) $id, (string) $this->autor, 'estudiante');

        self::assertNull($this->repo->findById($id));
        self::assertFalse($this->existe($publicId), 'Borrar el documento debe borrar sus adjuntos.');
    }

    private function crearDocumento(): ObjectId
    {
        $id = new ObjectId();
        $fecha = new UTCDateTime();
        $this->repo->insertar([
            '_id' => $id,
            'categoria' => 'proyecto',
            'titulo' => 'IT adjuntos',
            'descripcion' => 'Prueba de integración',
            'contenido' => null,
            'autor_id' => $this->autor,
            'integrantes' => [],
            'archivos_adjuntos' => [],
            'estado' => 'en_revision',
            'visible' => false,
            'fecha_creacion' => $fecha,
            'fecha_actualizacion' => $fecha,
        ]);

        return $id;
    }

    /**
     * @return array{int, string, string} [status HTTP, cuerpo, cabecera X-Cld-Error]
     */
    private static function descargar(string $url): array
    {
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        $error = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$error): int {
                if (stripos($linea, 'x-cld-error:') === 0) {
                    $error = trim(substr($linea, strlen('x-cld-error:')));
                }

                return strlen($linea);
            },
        ]);
        $cuerpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$status, is_string($cuerpo) ? $cuerpo : '', $error];
    }

    private static function configurado(Settings $s): bool
    {
        return $s->cloudinaryCloudName !== '' && $s->cloudinaryApiKey !== '' && $s->cloudinaryApiSecret !== '';
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

    /**
     * @return array{int, int} [ancho, alto] del archivo guardado en Cloudinary
     */
    private function dimensiones(string $publicId): array
    {
        $asset = self::admin($this->settings())->adminApi()->asset($publicId);

        return [(int) $asset['width'], (int) $asset['height']];
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
