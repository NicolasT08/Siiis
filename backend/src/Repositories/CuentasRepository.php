<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Driver\Session;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

use function MongoDB\with_transaction;

/**
 * Operaciones que escriben a la vez en `Credenciales` y `Usuarios`, en una transacción de Atlas
 * (spec 10.13.4). Los documentos llegan completos y con sus `_id` ya generados.
 */
class CuentasRepository
{
    private const DUPLICATE_KEY = 11000;

    public function __construct(private readonly Mongo $mongo)
    {
    }

    /**
     * @param array<string, mixed> $credencial
     * @param array<string, mixed> $usuario
     *
     * @throws DuplicateKeyException si el correo ya existe (índice único de `Credenciales.correo`)
     */
    public function crear(array $credencial, array $usuario): void
    {
        $credenciales = $this->mongo->collection(Collections::CREDENCIALES);
        $usuarios = $this->mongo->collection(Collections::USUARIOS);

        $session = $this->mongo->client()->startSession();
        try {
            with_transaction($session, static function (Session $session) use (
                $credenciales,
                $usuarios,
                $credencial,
                $usuario,
            ): void {
                $credenciales->insertOne($credencial, ['session' => $session]);
                $usuarios->insertOne($usuario, ['session' => $session]);
            });
        } catch (ServerException $e) {
            if (self::esClaveDuplicada($e)) {
                throw new DuplicateKeyException('Clave duplicada', 0, $e);
            }

            throw $e;
        } finally {
            $session->endSession();
        }
    }

    private static function esClaveDuplicada(ServerException $e): bool
    {
        if ($e->getCode() === self::DUPLICATE_KEY) {
            return true;
        }
        if ($e instanceof BulkWriteException) {
            foreach ($e->getWriteResult()->getWriteErrors() as $error) {
                if ($error->getCode() === self::DUPLICATE_KEY) {
                    return true;
                }
            }
        }

        return str_contains($e->getMessage(), 'E11000');
    }
}
