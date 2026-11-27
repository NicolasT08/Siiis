<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Security\GoogleTokenVerifier;
use Siiis\Security\RateLimiter;
use Siiis\Services\AuthService;
use Siiis\Support\ClientIp;
use Siiis\Support\JsonResponse;
use Siiis\Validation\GoogleLoginValidator;

/**
 * POST /api/v1/auth/google (RF-17, DEC-B61). Solo se registra si GOOGLE_CLIENT_ID está configurado.
 */
final class GoogleAuthController
{
    public function __construct(
        private readonly GoogleLoginValidator $validator,
        private readonly GoogleTokenVerifier $verifier,
        private readonly AuthService $auth,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $ip = ClientIp::from($request);
        $s = $this->settings;
        // Mismo límite que el login con contraseña, en un contador propio.
        $this->rateLimiter->limitar('login_google', $ip, $s->rateLimitLoginMax, $s->rateLimitLoginWindowSeconds);

        $correo = $this->verifier->verificar($this->validator->validate($request->getParsedBody()));

        return JsonResponse::ok($this->auth->loginConGoogle($correo, $ip))->withHeader('Cache-Control', 'no-store');
    }
}
