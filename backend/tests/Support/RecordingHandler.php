<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Handler PSR-15 que guarda la request recibida y responde 200, para probar middlewares aislados.
 */
final class RecordingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $received = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->received = $request;

        return (new ResponseFactory())->createResponse(200);
    }
}
