<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Repositories\MantenimientoCuentasRepository;
use Siiis\Services\EliminarCuentasService;
use Siiis\Tests\Support\FakeCloudinaryService;

/**
 * `scripts/eliminar_cuentas.php` y EliminarCuentasService contra Atlas (siiis_test), con Cloudinary simulado.
 * Cada prueba crea sus propias cuentas, documentos y reseñas, y borra lo que quede.
 */
final class EliminarCuentasIntegrationTest extends IntegrationTestCase
{
    private FakeCloudinaryService $cloudinary;

    private EliminarCuentasService $service;

    /** @var list<ObjectId> */
    private array $usuarios = [];

    /** @var list<ObjectId> */
    private array $credenciales = [];

    /** @var list<ObjectId> */
    private array $documentos = [];

    /** @var list<ObjectId> */
    private array $resenas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloudinary = new FakeCloudinaryService();
        $this->service = new EliminarCuentasService(new MantenimientoCuentasRepository($this->mongo()), $this->cloudinary);
        // Garantiza que, al borrar, siga habiendo un superadministrador activo.
        $this->cuenta(['rol' => 'superadministrador', 'estado' => true]);
    }

    protected function tearDown(): void
    {
        if (self::$mongo === null) {
            return;
        }
        $mongo = $this->mongo();
        $mongo->collection(Collections::CREDENCIALES)->deleteMany(['_id' => ['$in' => $this->credenciales]]);
        $mongo->collection(Collections::USUARIOS)->deleteMany(['_id' => ['$in' => $this->usuarios]]);
        $mongo->collection(Collections::SESIONES)->deleteMany(['usuario_id' => ['$in' => $this->usuarios]]);
        $mongo->collection(Collections::DOCUMENTOS)->deleteMany(['_id' => ['$in' => $this->documentos]]);
        $mongo->collection(Collections::RESENAS)->deleteMany(['_id' => ['$in' => $this->resenas]]);
    }

    public function testSimularNoBorraNada(): void
    {
        [$correo, $usuarioId] = $this->cuenta(['estado' => false, 'foto_perfil' => ['url' => 'https://x/f.jpg', 'public_id' => 'siiis/perfiles/it-sim']]);
        $this->sesion($usuarioId);
        $documento = $this->documento(['integrantes' => [$usuarioId]]);
        $this->resena($usuarioId);

        $salida = $this->script(['--correo=' . strtoupper($correo)], $codigo);

        self::assertSame(0, $codigo, $salida);
        self::assertStringContainsString('Base de datos: ' . $this->mongo()->databaseName(), $salida);
        self::assertStringNotContainsString('mongodb', mb_strtolower($salida), 'Nunca debe mostrar la URI.');
        self::assertStringContainsString('SIMULACIÓN', $salida);
        self::assertStringContainsString('Sesiones: 1 (1 activas)', $salida);
        self::assertStringContainsString('siiis/perfiles/it-sim', $salida);
        self::assertStringContainsString('Documentos como integrante: 1', $salida);
        self::assertStringContainsString((string) $documento, $salida);
        self::assertStringContainsString('Reseñas con su usuario_id: 1', $salida);
        self::assertStringContainsString('→ Se puede borrar.', $salida);

        self::assertSame(1, $this->contar(Collections::CREDENCIALES, ['correo' => $correo]));
        self::assertSame(1, $this->contar(Collections::USUARIOS, ['_id' => $usuarioId]));
        self::assertSame(1, $this->contar(Collections::SESIONES, ['usuario_id' => $usuarioId]));
        self::assertSame(1, $this->contar(Collections::DOCUMENTOS, ['integrantes' => $usuarioId]));
        self::assertSame(1, $this->contar(Collections::RESENAS, ['usuario_id' => $usuarioId]));
    }

    public function testRechazaUnaCuentaActiva(): void
    {
        [$correo, $usuarioId] = $this->cuenta(['estado' => true]);

        $reportes = $this->service->eliminar($this->service->revisar([$correo]));

        self::assertStringContainsString('activa', implode(' ', $reportes[0]['bloqueos']));
        self::assertArrayNotHasKey('resultado', $reportes[0]);
        self::assertSame(1, $this->contar(Collections::USUARIOS, ['_id' => $usuarioId]));
        self::assertSame(1, $this->contar(Collections::CREDENCIALES, ['correo' => $correo]));
    }

    public function testRechazaUnaCuentaAutoraYListaSusDocumentos(): void
    {
        [$correo, $usuarioId] = $this->cuenta(['estado' => false]);
        $documento = $this->documento(['autor_id' => $usuarioId, 'titulo' => 'Proyecto de la cuenta']);

        $salida = $this->script(['--correo=' . $correo, '--confirmar'], $codigo);

        self::assertSame(1, $codigo, $salida);
        self::assertStringContainsString('Documentos como autor: 1', $salida);
        self::assertStringContainsString('Proyecto de la cuenta', $salida);
        self::assertStringContainsString((string) $documento, $salida);
        self::assertStringContainsString('NO SE PUEDE BORRAR: es autora de 1 documento(s)', $salida);
        self::assertSame(1, $this->contar(Collections::USUARIOS, ['_id' => $usuarioId]));
        self::assertSame(1, $this->contar(Collections::CREDENCIALES, ['correo' => $correo]));
    }

    public function testNuncaTocaLaCuentaProtegida(): void
    {
        $reporte = $this->service->revisar(['SiiisWeb@gmail.com'])[0];

        self::assertFalse($reporte['encontrada']);
        self::assertStringContainsString('protegida', implode(' ', $reporte['bloqueos']));
    }

    public function testBorradoCompleto(): void
    {
        [$correo, $usuarioId, $credencialId] = $this->cuenta([
            'estado' => false,
            'foto_perfil' => ['url' => 'https://x/f.jpg', 'public_id' => 'siiis/perfiles/it-borrar'],
        ]);
        $this->sesion($usuarioId);
        $this->sesion($usuarioId, false);
        $otro = new ObjectId();
        $documento = $this->documento(['integrantes' => [$otro, $usuarioId]]);
        $resena = $this->resena($usuarioId);
        $this->cloudinary->fallarBorrado = true;

        $r = $this->service->eliminar($this->service->revisar([$correo]))[0];

        self::assertSame([], $r['bloqueos']);
        self::assertSame(['sesiones' => 2, 'integrantes' => 1, 'resenas' => 1, 'usuario' => 1, 'credencial' => 1], $r['resultado']);
        // Si Cloudinary falla, se reporta y el resto queda borrado.
        self::assertStringContainsString('siiis/perfiles/it-borrar', $r['foto']);
        self::assertSame(0, $this->contar(Collections::CREDENCIALES, ['_id' => $credencialId]));
        self::assertSame(0, $this->contar(Collections::USUARIOS, ['_id' => $usuarioId]));
        self::assertSame(0, $this->contar(Collections::SESIONES, ['usuario_id' => $usuarioId]));

        $doc = $this->mongo()->collection(Collections::DOCUMENTOS)->findOne(['_id' => $documento]);
        self::assertIsArray($doc);
        self::assertEquals([$otro], $doc['integrantes']);

        $res = $this->mongo()->collection(Collections::RESENAS)->findOne(['_id' => $resena]);
        self::assertIsArray($res);
        self::assertArrayNotHasKey('usuario_id', $res);
        self::assertSame('Visitante IT', $res['nombre_visible']);
    }

    public function testBorraLaFotoEnCloudinary(): void
    {
        [$correo] = $this->cuenta(['estado' => false, 'foto_perfil' => ['url' => 'https://x/f.jpg', 'public_id' => 'siiis/perfiles/it-foto']]);

        $r = $this->service->eliminar($this->service->revisar([$correo]))[0];

        self::assertSame('borrada', $r['foto']);
        self::assertSame(['siiis/perfiles/it-foto'], $this->cloudinary->borrados);
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * @param array<string, mixed> $usuario
     *
     * @return array{string, ObjectId, ObjectId} [correo, Usuarios._id, Credenciales._id]
     */
    private function cuenta(array $usuario): array
    {
        $correo = self::correoUnico();
        $credencialId = new ObjectId();
        $usuarioId = new ObjectId();
        $ahora = new UTCDateTime();
        $this->mongo()->collection(Collections::CREDENCIALES)->insertOne([
            '_id' => $credencialId,
            'correo' => $correo,
            'password' => 'x',
            'usuario_id' => $usuarioId,
            'estado_cuenta' => 'activa',
            'fecha_creacion' => $ahora,
        ]);
        $this->mongo()->collection(Collections::USUARIOS)->insertOne($usuario + [
            '_id' => $usuarioId,
            'credencial_id' => $credencialId,
            'nombre' => 'Cuenta',
            'apellido' => 'IT',
            'rol' => 'estudiante',
            'foto_perfil' => null,
            'estado' => false,
            'fecha_ingreso' => $ahora,
            'fecha_salida' => null,
        ]);
        $this->credenciales[] = $credencialId;
        $this->usuarios[] = $usuarioId;

        return [$correo, $usuarioId, $credencialId];
    }

    private function sesion(ObjectId $usuarioId, bool $activa = true): void
    {
        $this->mongo()->collection(Collections::SESIONES)->insertOne([
            'usuario_id' => $usuarioId,
            'token' => bin2hex(random_bytes(8)),
            'fecha_inicio' => new UTCDateTime(),
            'fecha_expiracion' => new UTCDateTime(),
            'ip_acceso' => '127.0.0.1',
            'activa' => $activa,
        ]);
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function documento(array $campos): ObjectId
    {
        $id = new ObjectId();
        $this->mongo()->collection(Collections::DOCUMENTOS)->insertOne($campos + [
            '_id' => $id,
            'categoria' => 'proyecto',
            'titulo' => 'Documento IT',
            'descripcion' => 'x',
            'contenido' => 'x',
            'autor_id' => new ObjectId(),
            'integrantes' => [],
            'archivos_adjuntos' => [],
            'estado' => 'en_revision',
            'visible' => false,
            'fecha_creacion' => new UTCDateTime(),
            'fecha_actualizacion' => new UTCDateTime(),
        ]);
        $this->documentos[] = $id;

        return $id;
    }

    private function resena(ObjectId $usuarioId): ObjectId
    {
        $id = new ObjectId();
        $this->mongo()->collection(Collections::RESENAS)->insertOne([
            '_id' => $id,
            'usuario_id' => $usuarioId,
            'nombre_visible' => 'Visitante IT',
            'comentario' => 'x',
            'calificacion' => 5,
            'estado' => 'visible',
            'fecha' => new UTCDateTime(),
        ]);
        $this->resenas[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $filtro
     */
    private function contar(string $coleccion, array $filtro): int
    {
        return $this->mongo()->collection($coleccion)->countDocuments($filtro);
    }

    /**
     * Ejecuta el script con --test (MONGO_DB_NAME_TEST) y devuelve su salida (stdout + stderr).
     *
     * @param list<string> $args
     */
    private function script(array $args, ?int &$codigo): string
    {
        $comando = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/eliminar_cuentas.php', '--test'], $args);
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        self::assertIsResource($proceso);
        $salida = (string) stream_get_contents($tuberias[1]) . (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);

        return $salida;
    }
}
