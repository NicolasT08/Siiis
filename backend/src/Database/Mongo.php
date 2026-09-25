<?php

declare(strict_types=1);

namespace Siiis\Database;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as DriverException;

/**
 * Cliente único a MongoDB Atlas por petición (spec 10.3 y 10.13.2). Sin reglas de negocio.
 */
class Mongo
{
    /** Evita que una petición quede colgada más que el max_execution_time del hosting. */
    private const SERVER_SELECTION_TIMEOUT_MS = 5000;

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private ?Client $client = null;

    public function __construct(
        private readonly string $uri,
        private readonly string $databaseName,
    ) {
    }

    public function client(): Client
    {
        return $this->client ??= new Client(
            $this->uri,
            ['serverSelectionTimeoutMS' => self::SERVER_SELECTION_TIMEOUT_MS],
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
