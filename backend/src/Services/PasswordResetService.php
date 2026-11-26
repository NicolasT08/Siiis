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
use Siiis\Support\Logger;
use Siiis\Support\TokenGenerator;
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
            $this->mail->send(
                $correo,
                'Restablece tu contraseña — Semillero SIIIS',
                $this->htmlCorreo($this->enlace($token), is_string($usuario['nombre'] ?? null) ? $usuario['nombre'] : ''),
                $this->textoCorreo($this->enlace($token)),
            );
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

    private function htmlCorreo(string $enlace, string $nombre): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $saludo = $nombre !== '' ? 'Hola, ' . $e($nombre) . ':' : 'Hola:';
        $minutos = $this->settings->passwordResetTtlMinutes;

        return <<<HTML
            <p>{$saludo}</p>
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta del Semillero SIIIS.</p>
            <p><a href="{$e($enlace)}">Restablecer contraseña</a></p>
            <p>El enlace vence en {$minutos} minutos y solo puede usarse una vez.</p>
            <p>Si no solicitaste este cambio, ignora este correo; tu contraseña no cambiará.</p>
            HTML;
    }

    private function textoCorreo(string $enlace): string
    {
        return "Para restablecer tu contraseña del Semillero SIIIS abre este enlace:\n{$enlace}\n\n"
            . "Vence en {$this->settings->passwordResetTtlMinutes} minutos. Si no lo solicitaste, ignora este correo.";
    }

    private static function tokenInvalido(): ApiException
    {
        return new ApiException(400, 'INVALID_OR_EXPIRED_TOKEN', 'El enlace de recuperación no es válido o ya venció.');
    }
}
