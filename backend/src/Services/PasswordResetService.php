<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\PasswordHasher;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\CorreoInstitucional;
use Siiis\Support\Logger;
use Siiis\Support\TokenGenerator;
use Siiis\Validation\ChangePasswordValidator;
use Throwable;

/**
 * Recuperación de contraseña con token de un solo uso (RF-04, spec 12.3).
 * En Credenciales.token_recuperacion solo se guarda el hash SHA-256 del token.
 */
class PasswordResetService
{
    public function __construct(
        private readonly CredencialesRepository $credenciales,
        private readonly UsuariosRepository $usuarios,
        private readonly SesionesRepository $sesiones,
        private readonly PasswordHasher $hasher,
        private readonly MailService $mail,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Genera y envía el enlace si la cuenta puede recuperarse. No revela nada al llamador:
     * cualquier caso termina sin excepción (el controlador responde siempre el mismo mensaje).
     */
    public function solicitar(string $correo): void
    {
        $credencial = $this->credenciales->findByCorreo($correo);
        $credencialId = $credencial['_id'] ?? null;
        if ($credencial === null || !$credencialId instanceof ObjectId) {
            return;
        }

        // DEC-B09: solo cuentas activas con usuario activo.
        $usuario = $this->usuarios->findByCredencialId($credencialId);
        if (($credencial['estado_cuenta'] ?? null) !== 'activa' || ($usuario['estado'] ?? null) !== true) {
            return;
        }

        $token = TokenGenerator::generate();
        $expiracion = $this->clock->now()->modify(sprintf('+%d minutes', $this->settings->passwordResetTtlMinutes));
        $this->credenciales->guardarTokenRecuperacion($credencialId, TokenGenerator::hash($token), $expiracion);

        try {
            $mensaje = $this->correo($this->enlace($token), is_string($usuario['nombre'] ?? null) ? $usuario['nombre'] : '');
            $this->mail->send($correo, 'Restablecimiento de contraseña - Semillero SIIIS', $mensaje['html'], $mensaje['text']);
        } catch (Throwable $e) {
            // El cliente recibe la respuesta genérica igualmente.
            $this->logger->error('No se pudo enviar el correo de recuperación', [
                'credencial_id' => (string) $credencialId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws ApiException 400 INVALID_OR_EXPIRED_TOKEN
     */
    public function restablecer(string $token, string $password): void
    {
        if (preg_match('/^[a-f0-9]{64}\z/', $token) !== 1) {
            throw self::tokenInvalido();
        }

        $tokenHash = TokenGenerator::hash($token);
        $credencial = $this->credenciales->findByTokenRecuperacion($tokenHash);
        $guardado = $credencial['token_recuperacion'] ?? null;
        $expiracion = $credencial['token_expiracion'] ?? null;
        $credencialId = $credencial['_id'] ?? null;
        $usuarioId = $credencial['usuario_id'] ?? null;

        if (
            !is_string($guardado) || !hash_equals($guardado, $tokenHash)
            || !$expiracion instanceof UTCDateTime || $expiracion->toDateTime() <= $this->clock->now()
            || !$credencialId instanceof ObjectId
        ) {
            throw self::tokenInvalido();
        }

        // Igual que change-password: la nueva no puede ser la que ya tenía la cuenta. El token no se consume.
        $actual = $credencial['password'] ?? null;
        if (is_string($actual) && $this->hasher->verify($password, $actual)) {
            throw ApiException::validation(['password' => ChangePasswordValidator::MISMA_PASSWORD]);
        }

        $this->credenciales->cambiarPassword($credencialId, $this->hasher->hash($password));
        if ($usuarioId instanceof ObjectId) {
            $this->sesiones->desactivarTodas($usuarioId);
        }
    }

    private function enlace(string $token): string
    {
        $base = $this->settings->passwordResetUrl;

        return $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }

    /**
     * @return array{html: string, text: string}
     */
    private function correo(string $enlace, string $nombre): array
    {
        $minutos = $this->settings->passwordResetTtlMinutes;

        return CorreoInstitucional::componer(
            $this->settings->mailLogoUrl,
            CorreoInstitucional::saludo($nombre),
            [
                'Recibimos una solicitud para restablecer la contraseña de su cuenta en el sitio del Semillero de '
                    . 'Investigación SIIIS. Para definir una nueva contraseña, haga clic en el siguiente botón:',
            ],
            boton: ['Restablecer contraseña', $enlace],
            avisos: [
                "Por seguridad, este enlace vence en {$minutos} minutos y es de un solo uso.",
                'Si usted no solicitó este cambio, puede ignorar este correo: su contraseña actual seguirá siendo '
                    . 'la misma.',
            ],
        );
    }

    private static function tokenInvalido(): ApiException
    {
        return new ApiException(400, 'INVALID_OR_EXPIRED_TOKEN', 'El enlace de recuperación no es válido o ya venció.');
    }
}
