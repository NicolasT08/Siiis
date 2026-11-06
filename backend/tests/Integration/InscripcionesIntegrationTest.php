<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Cloudinary\Cloudinary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Repositories\InscripcionesRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Services\InscripcionesService;
use Siiis\Support\ApiException;
use Siiis\Support\Logger;
use Siiis\Support\SystemClock;
use Siiis\Tests\Support\FakeMailService;
use Siiis\Tests\Support\TestImages;

/**
 * Inscripciones con Cloudinary real + MONGO_DB_NAME_TEST (DEC-B49 a DEC-B52). Sube solo a
 * siiis_test/inscripciones y la vacía al terminar. Comprueba lo verificado en DEC-B50: la URL guardada no abre
 * (401), el enlace firmado sí (200) y uno vencido no (401). Los correos se simulan. Se salta sin CLOUDINARY_*.
 */
final class InscripcionesIntegrationTest extends IntegrationTestCase
{
    /** Nunca la carpeta de producción (siiis/inscripciones). */
    private const FOLDER = 'siiis_test/inscripciones';

    private string $prefijo;

    private InscripcionesService $service;

    private InscripcionesRepository $repo;

    private CloudinaryService $cloudinary;

    private FakeMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::configurado($this->settings())) {
            self::markTestSkipped('CLOUDINARY_* no configuradas: se omiten las pruebas con Cloudinary.');
        }

        $settings = Settings::fromEnv(['CLOUDINARY_INSCRIPCIONES_FOLDER' => self::FOLDER] + $_ENV + getenv() + [
            'JWT_SECRET' => str_repeat('x', 32),
        ], $this->settings()->storagePath);
        $this->repo = new InscripcionesRepository($this->mongo());
        $this->cloudinary = new CloudinaryService($settings);
        $this->mail = new FakeMailService();
        $this->service = new InscripcionesService(
            $this->repo,
            $this->cloudinary,
            $this->mail,
            new SystemClock(),
            $settings,
            new Logger($settings->storagePath . '/logs'),
        );
        $this->prefijo = 'it-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        if (self::$mongo !== null && isset($this->prefijo)) {
            $coleccion = $this->mongo()->collection(Collections::INSCRIPCIONES);
            $filtro = ['nombre_completo' => $this->prefijo];
            foreach ($coleccion->find($filtro) as $inscripcion) {
                $documentos = is_array($inscripcion) ? ($inscripcion['documentos_adjuntos'] ?? []) : [];
                foreach (is_array($documentos) ? $documentos : [] as $documento) {
                    if (is_array($documento) && is_string($documento['public_id'] ?? null)) {
                        $this->cloudinary->delete($documento['public_id'], true);
                    }
                }
            }
            $coleccion->deleteMany($filtro);
            self::assertSame(0, $coleccion->countDocuments($filtro));
        }
        TestImages::limpiar();
    }

    public static function tearDownAfterClass(): void
    {
        $settings = self::$settings;
        if ($settings !== null && self::configurado($settings)) {
            // Red de seguridad: borra cualquier resto privado de la carpeta de pruebas.
            self::admin($settings)->adminApi()->deleteAssetsByPrefix(self::FOLDER . '/', [
                'type' => 'authenticated',
                'invalidate' => true,
            ]);
        }
        parent::tearDownAfterClass();
    }

    public function testDocumentosPrivadosConEnlacesQueVencen(): void
    {
        $recibida = $this->service->crear($this->datos(), [
            ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'size' => 1, 'nombre' => 'carta.pdf'],
            ['path' => TestImages::pngDeDimensiones(2000, 40), 'mime' => 'image/png', 'size' => 1, 'nombre' => 'notas.png'],
        ]);
        self::assertSame('pendiente', $recibida['estado_solicitud']);
        self::assertIsString($recibida['id']);

        // En Atlas: campos y tipos del modelo; URL sin el segmento de firma.
        $guardada = $this->repo->findById(new ObjectId($recibida['id']));
        self::assertNotNull($guardada);
        self::assertIsInt($guardada['semestre']);
        self::assertInstanceOf(UTCDateTime::class, $guardada['fecha_solicitud']);
        $documentos = $guardada['documentos_adjuntos'];
        self::assertIsArray($documentos);
        self::assertCount(2, $documentos);
        foreach ($documentos as $documento) {
            self::assertIsArray($documento);
            self::assertSame(['url', 'public_id', 'nombre_archivo'], array_keys($documento));
            self::assertIsString($documento['url']);
            self::assertStringStartsWith(self::FOLDER . '/', (string) $documento['public_id']);
            self::assertStringContainsString('/image/authenticated/', $documento['url']);
            self::assertStringNotContainsString('/s--', $documento['url']);
            // La URL guardada no abre sin firma (DEC-B50).
            self::assertSame(401, self::descargar($documento['url'])[0], $documento['url']);
        }

        // El detalle entrega enlaces firmados que sí abren.
        $detalle = $this->service->obtener($recibida['id']);
        self::assertIsArray($detalle['documentos_adjuntos']);
        [$pdf, $png] = $detalle['documentos_adjuntos'];
        self::assertSame(['carta.pdf', 'pdf'], [$pdf['nombre_archivo'], $pdf['formato']]);
        [$status, $cuerpo] = self::descargar($pdf['url']);
        self::assertSame(200, $status);
        self::assertStringStartsWith('%PDF', $cuerpo);
        [$status, $cuerpo] = self::descargar($png['url']);
        self::assertSame(200, $status);
        $dimensiones = getimagesizefromstring($cuerpo);
        self::assertNotFalse($dimensiones);
        // Misma reducción que los adjuntos (DEC-B42).
        self::assertSame([1600, 32], [$dimensiones[0], $dimensiones[1]]);
        self::assertGreaterThan(time() + 3500, strtotime($pdf['expira']));

        // Un enlace vencido no abre.
        $publicId = $documentos[0]['public_id'];
        self::assertIsString($publicId);
        $vencido = $this->cloudinary->enlacePrivado($publicId, 'pdf', time() - 60);
        self::assertSame(401, self::descargar($vencido)[0]);

        // Correos simulados: confirmación y aviso.
        self::assertCount(2, $this->mail->enviados);
    }

    public function testDuplicadoYResolucionAtomicaEnAtlas(): void
    {
        $id = (string) $this->service->crear($this->datos(), [
            ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'size' => 1, 'nombre' => 'carta.pdf'],
        ])['id'];

        // El mismo correo con una solicitud pendiente: 409 sin subir nada.
        try {
            $this->service->crear($this->datos(), [
                ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'size' => 1, 'nombre' => 'otra.pdf'],
            ]);
            self::fail('Se esperaba 409');
        } catch (ApiException $e) {
            self::assertSame('INSCRIPTION_PENDING', $e->errorCode);
        }

        // Listado: proyección sin carta ni documentos; filtro por el índice oficial.
        $pagina = $this->repo->paginar('pendiente', 1, 50)['items'];
        $propia = array_values(array_filter($pagina, static fn (array $i): bool => (string) $i['_id'] === $id));
        self::assertCount(1, $propia);
        self::assertArrayNotHasKey('carta_motivacion', $propia[0]);
        self::assertArrayNotHasKey('documentos_adjuntos', $propia[0]);

        // Resolver: atómico, una sola vez.
        self::assertSame('rechazada', $this->service->resolver($id, 'rechazada')['estado_solicitud']);
        try {
            $this->service->resolver($id, 'aceptada');
            self::fail('Se esperaba 409');
        } catch (ApiException $e) {
            self::assertSame('INSCRIPTION_CLOSED', $e->errorCode);
        }
        self::assertSame('rechazada', $this->repo->findById(new ObjectId($id))['estado_solicitud'] ?? null);
        self::assertFalse($this->repo->resolver(new ObjectId($id), 'aceptada'));

        // Tras el rechazo puede volver a inscribirse.
        self::assertFalse($this->repo->tienePendiente($this->correo()));
        $this->service->crear($this->datos(), [
            ['path' => TestImages::pdf(), 'mime' => 'application/pdf', 'size' => 1, 'nombre' => 'carta.pdf'],
        ]);
        self::assertTrue($this->repo->tienePendiente($this->correo()));
    }

    /**
     * @return array{nombre_completo: string, correo: string, telefono: string|null, programa_academico: string,
     *               semestre: int, carta_motivacion: string}
     */
    private function datos(): array
    {
        return [
            'nombre_completo' => $this->prefijo,
            'correo' => $this->correo(),
            'telefono' => null,
            'programa_academico' => 'Ingeniería de Sistemas',
            'semestre' => 4,
            'carta_motivacion' => str_repeat('Carta de integración. ', 6),
        ];
    }

    private function correo(): string
    {
        return $this->prefijo . '@uptc.edu.co';
    }

    /**
     * @return array{int, string} [status HTTP, cuerpo]
     */
    private static function descargar(string $url): array
    {
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $cuerpo = curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_string($cuerpo) ? $cuerpo : ''];
    }

    private static function configurado(Settings $s): bool
    {
        return $s->cloudinaryCloudName !== '' && $s->cloudinaryApiKey !== '' && $s->cloudinaryApiSecret !== '';
    }

    private static function admin(Settings $s): Cloudinary
    {
        return new Cloudinary([
            'cloud' => [
                'cloud_name' => $s->cloudinaryCloudName,
                'api_key' => $s->cloudinaryApiKey,
                'api_secret' => $s->cloudinaryApiSecret,
            ],
            'url' => ['secure' => true],
        ]);
    }
}
