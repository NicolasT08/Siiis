<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Application;
use Siiis\Config\Settings;
use Siiis\Tests\Support\TestSettings;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Base para pruebas de API: la app Slim en memoria con dependencias sustituidas por dobles.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * @param array<string, mixed>  $overrides definiciones del contenedor
     * @param array<string, string> $env
     *
     * @return App<\Psr\Container\ContainerInterface>
     */
    protected function app(array $overrides = [], array $env = []): App
    {
        return Application::create($this->settings($env), $overrides);
    }

    /**
     * @param array<string, string> $env
     */
    protected function settings(array $env = []): Settings
    {
        return TestSettings::make($env);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $path,
        mixed $body = null,
        array $headers = [],
        string $ip = '203.0.113.10',
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => $ip]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream($raw));
        }

        return $request;
    }

    /**
     * @return array<mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function assertError(ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $body = $this->json($response);
        self::assertIsArray($body['error'] ?? null);
        self::assertSame($code, $body['error']['code'] ?? null);
        self::assertIsString($body['error']['message'] ?? null);
    }
}
