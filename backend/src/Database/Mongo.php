<?php

declare(strict_types=1);

namespace Siiis\Database;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as DriverException;
use Siiis\Config\Settings;

/**
 * Cliente único a MongoDB Atlas por petición (spec 10.3 y 10.13.2). Sin reglas de negocio.
 *
 * Timeouts (auditoría M-01): en Linux, max_execution_time no cuenta la espera de red, así que sin ellos una
 * petición podía quedar colgada minutos si Atlas respondía lento. Al vencer, el driver lanza una
 * ConnectionException (o ConnectionTimeoutException) y el ErrorHandler responde 503 DB_UNAVAILABLE.
 * - serverSelectionTimeoutMS: cuánto esperar a encontrar un servidor disponible.
 * - connectTimeoutMS: cuánto esperar a abrir cada conexión TCP/TLS.
 * - socketTimeoutMS: cuánto esperar la respuesta de una operación ya enviada.
 */
class Mongo
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private ?Client $client = null;

    public function __construct(
        private readonly string $uri,
        private readonly string $databaseName,
        private readonly int $serverSelectionTimeoutMs = 5000,
        private readonly int $connectTimeoutMs = 5000,
        private readonly int $socketTimeoutMs = 20000,
    ) {
    }

    /**
     * Cliente con los timeouts de la configuración. $databaseName distinto del de Settings: scripts con --test.
     */
    public static function desde(Settings $settings, ?string $databaseName = null): self
    {
        return new self(
            $settings->mongoUri,
            $databaseName ?? $settings->mongoDbName,
            $settings->mongoServerSelectionTimeoutMs,
            $settings->mongoConnectTimeoutMs,
            $settings->mongoSocketTimeoutMs,
        );
    }

    public function client(): Client
    {
        return $this->client ??= new Client(
            $this->uri,
            [
                'serverSelectionTimeoutMS' => $this->serverSelectionTimeoutMs,
                'connectTimeoutMS' => $this->connectTimeoutMs,
                'socketTimeoutMS' => $this->socketTimeoutMs,
            ],
            ['typeMap' => self::TYPE_MAP],
        );
    }

    public function database(): Database
    {
        return $this->client()->selectDatabase($this->databaseName);
    }

    public function collection(string $name): Collection
    {
        return $this->database()->selectCollection($name);
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * Comprueba la conexión con un comando ping. No lanza excepciones.
     */
    public function ping(): bool
    {
        try {
            $this->database()->command(['ping' => 1]);

            return true;
        } catch (DriverException) {
            return false;
        }
    }
}
