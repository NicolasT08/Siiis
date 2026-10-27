<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use DateTimeImmutable;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;

final class RepositoriesIntegrationTest extends IntegrationTestCase
{
    /** @var list<array{string, ObjectId}> */
    private array $creados = [];

    protected function tearDown(): void
    {
        foreach ($this->creados as [$coleccion, $id]) {
            $this->mongo()->collection($coleccion)->deleteOne(['_id' => $id]);
        }
        $this->creados = [];
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function insertar(string $coleccion, array $doc): ObjectId
    {
        $id = new ObjectId();
        $this->mongo()->collection($coleccion)->insertOne(['_id' => $id] + $doc);
        $this->creados[] = [$coleccion, $id];

        return $id;
    }

    public function testCredencialesYUsuarios(): void
    {
        $correo = self::correoUnico();
        $usuarioId = new ObjectId();
        $credId = $this->insertar(Collections::CREDENCIALES, [
            'correo' => $correo,
            'password' => 'hash',
            'usuario_id' => $usuarioId,
            'estado_cuenta' => 'activa',
            'intentos_fallidos' => 0,
        ]);
        $this->mongo()->collection(Collections::USUARIOS)->insertOne([
            '_id' => $usuarioId, 'credencial_id' => $credId, 'nombre' => 'IT', 'apellido' => 'Test',
            'rol' => 'estudiante', 'estado' => true, 'biografia' => 'no se proyecta',
        ]);
        $this->creados[] = [Collections::USUARIOS, $usuarioId];

        $credenciales = new CredencialesRepository($this->mongo());
        $usuarios = new UsuariosRepository($this->mongo());

        self::assertSame((string) $credId, (string) $credenciales->findByCorreo($correo)['_id']);
        self::assertNull($credenciales->findByCorreo('no-' . $correo));
        self::assertSame(1, $credenciales->registrarIntentoFallido($credId));
        self::assertSame(2, $credenciales->registrarIntentoFallido($credId));

        $credenciales->registrarAccesoExitoso($credId, new DateTimeImmutable(), 'nuevo-hash');
        $doc = $credenciales->findByCorreo($correo);
        self::assertSame(0, $doc['intentos_fallidos']);
        self::assertSame('nuevo-hash', $doc['password']);
        self::assertInstanceOf(UTCDateTime::class, $doc['ultimo_acceso']);

        $credenciales->guardarTokenRecuperacion($credId, 'hash-token', new DateTimeImmutable('+30 minutes'));
        self::assertSame((string) $credId, (string) $credenciales->findByTokenRecuperacion('hash-token')['_id']);
        $credenciales->cambiarPassword($credId, 'otro-hash');
        self::assertNull($credenciales->findByTokenRecuperacion('hash-token'));

        $usuario = $usuarios->findByCredencialId($credId);
        self::assertSame('IT', $usuario['nombre']);
        self::assertArrayNotHasKey('biografia', $usuario);
        self::assertSame((string) $usuarioId, (string) $usuarios->findById($usuarioId)['_id']);
    }

    public function testUsuariosPaginarOrdenEnEspanolYProyeccion(): void
    {
        // Rol y estado poco comunes para no depender de otros documentos de siiis_test.
        $marca = 'IT-' . bin2hex(random_bytes(3));
        $credencialIds = [];
        foreach (['Zapata', 'bernal', 'Álvarez'] as $apellido) {
            $credencialIds[] = $credencialId = $this->insertar(Collections::CREDENCIALES, [
                'correo' => self::correoUnico(), 'password' => 'hash', 'estado_cuenta' => 'activa',
            ]);
            $this->insertar(Collections::USUARIOS, [
                'credencial_id' => $credencialId, 'nombre' => $marca, 'apellido' => $apellido,
                'rol' => 'docente_director', 'estado' => false, 'telefono' => '+573000000000',
            ]);
        }

        $repo = new UsuariosRepository($this->mongo());
        $pagina = $repo->paginar('docente_director', false, 1, 1000);
        $propios = array_values(array_filter($pagina['items'], static fn (array $u): bool => $u['nombre'] === $marca));

        self::assertSame(['Álvarez', 'bernal', 'Zapata'], array_column($propios, 'apellido'));
        self::assertGreaterThanOrEqual(3, $pagina['total']);
        self::assertSame('+573000000000', $propios[0]['telefono']);
        self::assertSame($pagina['total'], $repo->paginar('docente_director', false, 2, 1000)['total']);
        self::assertCount(1, $repo->paginar('docente_director', false, 1, 1)['items']);

        $perfil = $repo->findPerfilById($propios[0]['_id']);
        self::assertNotNull($perfil);
        self::assertSame('+573000000000', $perfil['telefono']);

        $cuentas = (new CredencialesRepository($this->mongo()))->findCuentasByIds($credencialIds);
        self::assertCount(3, $cuentas);
        foreach ($cuentas as $id => $cuenta) {
            self::assertSame($id, (string) $cuenta['_id']);
            self::assertSame(['_id', 'correo', 'estado_cuenta'], array_keys($cuenta));
        }
    }

    public function testUsuariosActualizarYContarSuperadministradores(): void
    {
        $repo = new UsuariosRepository($this->mongo());
        $antes = $repo->contarSuperadministradoresActivos();
        $id = $this->insertar(Collections::USUARIOS, [
            'nombre' => 'IT', 'apellido' => 'Super', 'rol' => 'superadministrador', 'estado' => true,
            'biografia' => 'Se conserva',
        ]);
        self::assertSame($antes + 1, $repo->contarSuperadministradoresActivos());

        $repo->actualizar($id, ['rol' => 'estudiante', 'nombre' => 'IT2', 'semestre' => null]);

        $perfil = $repo->findPerfilById($id);
        self::assertNotNull($perfil);
        self::assertSame('estudiante', $perfil['rol']);
        self::assertSame('IT2', $perfil['nombre']);
        self::assertNull($perfil['semestre']);
        self::assertSame('Se conserva', $perfil['biografia']);
        self::assertSame($antes, $repo->contarSuperadministradoresActivos());
    }

    public function testCorreoUnicoPorIndice(): void
    {
        $correo = self::correoUnico();
        $this->insertar(Collections::CREDENCIALES, ['correo' => $correo]);

        $this->expectException(\MongoDB\Driver\Exception\BulkWriteException::class);
        $this->insertar(Collections::CREDENCIALES, ['correo' => $correo]);
    }

    public function testSesiones(): void
    {
        $repo = new SesionesRepository($this->mongo());
        $usuarioId = new ObjectId();
        $ahora = new DateTimeImmutable();
        $hashA = hash('sha256', bin2hex(random_bytes(8)));
        $hashB = hash('sha256', bin2hex(random_bytes(8)));

        $repo->crear($usuarioId, $hashA, $ahora, $ahora->modify('+1 hour'), '127.0.0.1');
        $repo->crear($usuarioId, $hashB, $ahora, $ahora->modify('+1 hour'), '127.0.0.1');
        try {
            self::assertNotNull($repo->findActiva($hashA, $ahora));
            self::assertNull($repo->findActiva($hashA, $ahora->modify('+2 hours')), 'sesión vencida');

            $repo->desactivar($hashA);
            self::assertNull($repo->findActiva($hashA, $ahora));
            self::assertNotNull($repo->findActiva($hashB, $ahora));

            $repo->desactivarTodas($usuarioId);
            self::assertNull($repo->findActiva($hashB, $ahora));
        } finally {
            $this->mongo()->collection(Collections::SESIONES)->deleteMany(['usuario_id' => $usuarioId]);
        }
    }

    public function testMultimediaProyeccionYOrden(): void
    {
        $marca = bin2hex(random_bytes(4));
        foreach ([3, 1, 2] as $orden) {
            $this->insertar(Collections::MULTIMEDIA, [
                'tipo' => 'imagen', 'url' => "https://example.test/$marca/$orden.jpg", 'public_id' => "secreto-$marca",
                'seccion' => 'galeria', 'descripcion' => 'IT', 'orden' => $orden, 'fecha_subida' => new UTCDateTime(),
            ]);
        }

        $items = (new MultimediaRepository($this->mongo()))->findBySeccion('galeria', 100);

        $ordenes = array_column($items, 'orden');
        $ordenados = $ordenes;
        sort($ordenados);
        self::assertSame($ordenados, $ordenes);
        foreach ($items as $item) {
            self::assertSame(['_id', 'tipo', 'url', 'descripcion', 'orden'], array_keys($item));
        }
        self::assertCount(1, (new MultimediaRepository($this->mongo()))->findBySeccion('galeria', 1));
    }
}
