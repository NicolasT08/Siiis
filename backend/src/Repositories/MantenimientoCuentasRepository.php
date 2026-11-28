<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use MongoDB\Driver\Session;
use RuntimeException;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Security\Roles;

use function MongoDB\with_transaction;

/**
 * Consultas del script de mantenimiento `scripts/eliminar_cuentas.php` (DEC-B73): buscan todo lo que referencia a
 * una cuenta y la borran de forma definitiva en una transacción de Atlas. No lo usa la API.
 */
class MantenimientoCuentasRepository
{
    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credencialPorCorreo(string $correo): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->collection(Collections::CREDENCIALES)->findOne(
            ['correo' => $correo],
            ['projection' => ['correo' => 1, 'usuario_id' => 1, 'estado_cuenta' => 1]],
        );
    }

    /**
     * Perfil por su `_id` o, si la credencial no lo enlaza, por `credencial_id`.
     *
     * @return array<string, mixed>|null
     */
    public function usuario(?ObjectId $usuarioId, ObjectId $credencialId): ?array
    {
        $proyeccion = ['projection' => ['credencial_id' => 1, 'nombre' => 1, 'apellido' => 1, 'rol' => 1, 'estado' => 1, 'foto_perfil' => 1]];
        $usuarios = $this->collection(Collections::USUARIOS);
        if ($usuarioId !== null) {
            /** @var array<string, mixed>|null $usuario */
            $usuario = $usuarios->findOne(['_id' => $usuarioId], $proyeccion);
            if ($usuario !== null) {
                return $usuario;
            }
        }

        /** @var array<string, mixed>|null */
        return $usuarios->findOne(['credencial_id' => $credencialId], $proyeccion);
    }

    /**
     * @return array{total: int, activas: int}
     */
    public function contarSesiones(ObjectId $usuarioId): array
    {
        $sesiones = $this->collection(Collections::SESIONES);

        return [
            'total' => $sesiones->countDocuments(['usuario_id' => $usuarioId]),
            'activas' => $sesiones->countDocuments(['usuario_id' => $usuarioId, 'activa' => true]),
        ];
    }

    /**
     * @return list<array{id: string, categoria: string, titulo: string, estado: string}>
     */
    public function documentosComoAutor(ObjectId $usuarioId): array
    {
        return $this->documentos(['autor_id' => $usuarioId]);
    }

    /**
     * @return list<array{id: string, categoria: string, titulo: string, estado: string}>
     */
    public function documentosComoIntegrante(ObjectId $usuarioId): array
    {
        return $this->documentos(['integrantes' => $usuarioId]);
    }

    public function contarResenas(ObjectId $usuarioId): int
    {
        return $this->collection(Collections::RESENAS)->countDocuments(['usuario_id' => $usuarioId]);
    }

    /** Solo informativo: las inscripciones y los mensajes guardan el correo como dato, no como referencia. */
    public function contarPorCorreo(string $coleccion, string $correo): int
    {
        return $this->collection($coleccion)->countDocuments(['correo' => $correo]);
    }

    /**
     * @param list<ObjectId> $excluir
     */
    public function contarSuperadministradoresActivos(array $excluir): int
    {
        return $this->collection(Collections::USUARIOS)->countDocuments([
            'rol' => Roles::SUPERADMINISTRADOR,
            'estado' => true,
            '_id' => ['$nin' => $excluir],
        ]);
    }

    /**
     * Borra la cuenta y sus referencias en una transacción. Vuelve a comprobar dentro de ella que el perfil siga
     * inactivo y sin documentos como autor; si no, no cambia nada.
     *
     * @return array{sesiones: int, integrantes: int, resenas: int, usuario: int, credencial: int}
     *
     * @throws RuntimeException si la cuenta cambió desde la revisión
     */
    public function eliminar(ObjectId $credencialId, ?ObjectId $usuarioId): array
    {
        $resultado = ['sesiones' => 0, 'integrantes' => 0, 'resenas' => 0, 'usuario' => 0, 'credencial' => 0];
        $credenciales = $this->collection(Collections::CREDENCIALES);
        $usuarios = $this->collection(Collections::USUARIOS);
        $sesiones = $this->collection(Collections::SESIONES);
        $documentos = $this->collection(Collections::DOCUMENTOS);
        $resenas = $this->collection(Collections::RESENAS);

        $session = $this->mongo->client()->startSession();
        try {
            with_transaction($session, static function (Session $session) use (
                &$resultado,
                $credencialId,
                $usuarioId,
                $credenciales,
                $usuarios,
                $sesiones,
                $documentos,
                $resenas,
            ): void {
                $op = ['session' => $session];
                $resultado = ['sesiones' => 0, 'integrantes' => 0, 'resenas' => 0, 'usuario' => 0, 'credencial' => 0];
                if ($usuarioId !== null) {
                    if ($usuarios->countDocuments(['_id' => $usuarioId, 'estado' => true], $op) > 0) {
                        throw new RuntimeException('la cuenta se activó durante la revisión.');
                    }
                    if ($documentos->countDocuments(['autor_id' => $usuarioId], $op) > 0) {
                        throw new RuntimeException('la cuenta pasó a ser autora de un documento durante la revisión.');
                    }
                    $resultado['sesiones'] = (int) $sesiones->deleteMany(['usuario_id' => $usuarioId], $op)->getDeletedCount();
                    $resultado['integrantes'] = (int) $documentos->updateMany(
                        ['integrantes' => $usuarioId],
                        ['$pull' => ['integrantes' => $usuarioId]],
                        $op,
                    )->getModifiedCount();
                    // La reseña se conserva con su nombre_visible; deja de enlazar al usuario (spec 8.2: nullable).
                    $resultado['resenas'] = (int) $resenas->updateMany(
                        ['usuario_id' => $usuarioId],
                        ['$unset' => ['usuario_id' => '']],
                        $op,
                    )->getModifiedCount();
                    $resultado['usuario'] = (int) $usuarios->deleteOne(['_id' => $usuarioId], $op)->getDeletedCount();
                }
                $resultado['credencial'] = (int) $credenciales->deleteOne(['_id' => $credencialId], $op)->getDeletedCount();
            });
        } finally {
            $session->endSession();
        }

        return $resultado;
    }

    /**
     * @param array<string, mixed> $filtro
     *
     * @return list<array{id: string, categoria: string, titulo: string, estado: string}>
     */
    private function documentos(array $filtro): array
    {
        $cursor = $this->collection(Collections::DOCUMENTOS)->find($filtro, [
            'projection' => ['categoria' => 1, 'titulo' => 1, 'estado' => 1],
            'sort' => ['fecha_creacion' => 1, '_id' => 1],
        ]);

        $documentos = [];
        foreach ($cursor as $documento) {
            /** @var array<string, mixed> $documento */
            $documentos[] = [
                'id' => (string) $documento['_id'],
                'categoria' => (string) ($documento['categoria'] ?? ''),
                'titulo' => (string) ($documento['titulo'] ?? ''),
                'estado' => (string) ($documento['estado'] ?? ''),
            ];
        }

        return $documentos;
    }

    private function collection(string $nombre): Collection
    {
        return $this->mongo->collection($nombre);
    }
}
