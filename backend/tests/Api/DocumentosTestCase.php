<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Base de las pruebas de contenido (/projects y /articles): app con repositorios en memoria y ayudas
 * para sembrar usuarios y documentos.
 */
abstract class DocumentosTestCase extends AuthTestCase
{
    /** Categoría que usa `documento()` si no se indica otra. */
    protected const CATEGORIA = 'proyecto';

    /** @var App<\Psr\Container\ContainerInterface> */
    protected App $app;

    private int $segundos = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function revisores(): iterable
    {
        yield 'superadministrador' => ['superadministrador'];
        yield 'administrador' => ['administrador'];
        yield 'docente_director' => ['docente_director'];
    }

    protected function get(string $path, ?string $token = null): ResponseInterface
    {
        return $this->send('GET', $path, null, $token);
    }

    protected function send(string $method, string $path, mixed $body = null, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request($method, $path, $body, $token === null ? [] : self::bearer($token)));
    }

    /**
     * @return list<string> ids de `data` en el orden de la respuesta
     */
    protected function ids(string $path, ?string $token = null): array
    {
        return array_column($this->json($this->get($path, $token))['data'], 'id');
    }

    /**
     * @param array<string, mixed> $campos
     */
    protected function usuario(string $rol, array $campos = []): ObjectId
    {
        [, $id] = $this->db->crearCuenta('u-' . bin2hex(random_bytes(4)) . '@uptc.edu.co', self::PASSWORD, [], ['rol' => $rol] + $campos);

        return $id;
    }

    /**
     * Inserta un documento directamente; cada uno un segundo más reciente que el anterior.
     *
     * @param array<string, mixed> $campos
     */
    protected function documento(array $campos): ObjectId
    {
        $id = new ObjectId();
        $fecha = new UTCDateTime(($this->clock->now()->getTimestamp() + ++$this->segundos) * 1000);
        $this->db->documentos[(string) $id] = $campos + [
            '_id' => $id,
            'categoria' => static::CATEGORIA,
            'titulo' => 'Proyecto IT',
            'descripcion' => 'Descripción breve',
            'contenido' => 'Contenido',
            'autor_id' => new ObjectId(),
            'integrantes' => [],
            'archivos_adjuntos' => [],
            'estado' => 'en_revision',
            'visible' => false,
            'fecha_creacion' => $fecha,
            'fecha_actualizacion' => $fecha,
        ];

        return $id;
    }
}
