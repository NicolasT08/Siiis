<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Security\RateLimiter;
use Siiis\Services\AuthService;
use Siiis\Services\PasswordResetService;
use Siiis\Services\UserAccountService;
use Siiis\Support\ClientIp;
use Siiis\Support\JsonResponse;
use Siiis\Validation\ChangePasswordValidator;
use Siiis\Validation\ForgotPasswordValidator;
use Siiis\Validation\LoginValidator;
use Siiis\Validation\RegisterValidator;
use Siiis\Validation\ResetPasswordValidator;

/**
 * Endpoints de autenticación local (spec 10.7 y 11.3). Login con Google: fuera de las fases 1 y 2.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
        private readonly LoginValidator $loginValidator,
        private readonly PasswordResetService $passwordReset,
        private readonly ForgotPasswordValidator $forgotValidator,
        private readonly ResetPasswordValidator $resetValidator,
        private readonly RegisterValidator $registerValidator,
        private readonly UserAccountService $accounts,
        private readonly ChangePasswordValidator $changeValidator,
    ) {
    }

    /**
     * POST /api/v1/auth/change-password — protegido (PROPUESTA DEC-B23). Rate limit por IP antes de validar.
     */
    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar(
            'change-password',
            ClientIp::from($request),
            $s->rateLimitChangePasswordMax,
            $s->rateLimitChangePasswordWindowSeconds,
        );

        $input = $this->changeValidator->validate($request->getParsedBody());
        $this->auth->cambiarPassword(
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_SESSION_HASH),
            $input['password_actual'],
            $input['password_nueva'],
        );

        return JsonResponse::ok([
            'mensaje' => 'Tu contraseña fue actualizada. Se cerraron tus sesiones en otros dispositivos.',
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/auth/register — solo superadministrador y administrador (DEC-B16, DEC-B17).
     */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->registerValidator->validate($request->getParsedBody());
        $actorRol = (string) $request->getAttribute(AuthMiddleware::ATTR_ROL);

        return JsonResponse::ok(['usuario' => $this->accounts->registrar($actorRol, $input)], 201)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $ip = ClientIp::from($request);
        $s = $this->settings;
        $this->rateLimiter->limitar('login', $ip, $s->rateLimitLoginMax, $s->rateLimitLoginWindowSeconds);

        $input = $this->loginValidator->validate($request->getParsedBody());

        return JsonResponse::ok($this->auth->login($input['correo'], $input['password'], $ip))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $usuarioId = (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID);

        return JsonResponse::ok(['usuario' => $this->auth->usuarioActual($usuarioId)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/auth/logout (PROPUESTA DEC-B05)
     */
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout((string) $request->getAttribute(AuthMiddleware::ATTR_SESSION_HASH));

        return JsonResponse::noContent();
    }

    /**
     * POST /api/v1/auth/forgot-password — siempre la misma respuesta, exista o no el correo.
     */
    public function forgotPassword(ServerRequestInterface $request): ResponseInterface
    {
        $ip = ClientIp::from($request);
        $s = $this->settings;
        $this->rateLimiter->limitar('forgot', $ip, $s->rateLimitForgotMax, $s->rateLimitForgotWindowSeconds);

        $input = $this->forgotValidator->validate($request->getParsedBody());
        $this->passwordReset->solicitar($input['correo']);

        return JsonResponse::ok([
            'mensaje' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password — rate limit por IP antes de validar (auditoría M-03).
     */
    public function resetPassword(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar('reset', ClientIp::from($request), $s->rateLimitResetMax, $s->rateLimitResetWindowSeconds);

        $input = $this->resetValidator->validate($request->getParsedBody());
        $this->passwordReset->restablecer($input['token'], $input['password']);

        return JsonResponse::ok([
            'mensaje' => 'Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña.',
        ]);
    }
}
