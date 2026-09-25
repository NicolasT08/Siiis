<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use Siiis\Config\Settings;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\JwtService;
use Siiis\Security\PasswordHasher;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\Logger;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\Serializer;
use Siiis\Support\TokenGenerator;

/**
 * Reglas de autenticación local (RF-02, RF-04, RNF-05; spec 12 y 10.13.6).
 */
class AuthService
{
    public function __construct(
        private readonly CredencialesRepository $credenciales,
        private readonly UsuariosRepository $usuarios,
        private readonly SesionesRepository $sesiones,
        private readonly PasswordHasher $hasher,
        private readonly JwtService $jwt,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Login local. Orden anti-enumeración (DEC-B07): contraseña → estado de cuenta → usuario.
     *
     * @return array{token: string, expira_en: int, usuario: array<string, mixed>}
     *
     * @throws ApiException
     */
    public function login(string $correo, string $password, string $ip): array
    {
        $credencial = $this->credenciales->findByCorreo($correo);
        $hash = $credencial['password'] ?? null;
        $credencialId = $credencial['_id'] ?? null;

        if ($credencial === null || !is_string($hash) || !$credencialId instanceof ObjectId) {
            $this->hasher->dummyVerify($password);

            throw self::invalidCredentials();
        }

        if (!$this->hasher->verify($password, $hash)) {
            $intentos = $this->credenciales->registrarIntentoFallido($credencialId);
            if ($intentos >= $this->settings->maxLoginAttempts) {
                // DEC-B06: umbral alcanzado; el bloqueo está pendiente de decisión, solo se registra.
                $this->logger->warning('Umbral de intentos fallidos alcanzado', [
                    'credencial_id' => (string) $credencialId,
                    'intentos' => $intentos,
                ]);
            }

            throw self::invalidCredentials();
        }

        match ($credencial['estado_cuenta'] ?? null) {
            'activa' => null,
            'pendiente_verificacion' => throw new ApiException(
                403,
                'ACCOUNT_PENDING',
                'La cuenta está pendiente de verificación.',
            ),
            default => throw new ApiException(403, 'ACCOUNT_BLOCKED', 'La cuenta está bloqueada.'),
        };

        $usuario = $this->usuarios->findByCredencialId($credencialId);
        $usuarioId = $usuario['_id'] ?? null;
        $rol = $usuario['rol'] ?? null;
        if ($usuario === null || !$usuarioId instanceof ObjectId || !is_string($rol)) {
            $this->logger->error('Credencial sin perfil de Usuarios válido', ['credencial_id' => (string) $credencialId]);

            throw self::invalidCredentials();
        }
        if (($usuario['estado'] ?? null) !== true) {
            throw new ApiException(403, 'ACCOUNT_INACTIVE', 'El usuario está inactivo.');
        }

        $issued = $this->jwt->issue((string) $usuarioId, $rol);
        $this->sesiones->crear(
            $usuarioId,
            TokenGenerator::hash($issued->jti),
            $issued->issuedAt,
            $issued->expiresAt,
            $ip,
        );
        $this->credenciales->registrarAccesoExitoso(
            $credencialId,
            $this->clock->now(),
            $this->hasher->needsRehash($hash) ? $this->hasher->hash($password) : null,
        );

        return [
            'token' => $issued->token,
            'expira_en' => $issued->expiresIn(),
            'usuario' => self::usuarioPublico($usuario),
        ];
    }

    /**
     * Perfil del usuario autenticado; 401 si ya no existe o fue desactivado.
     *
     * @return array<string, mixed>
     */
    public function usuarioActual(string $usuarioId): array
    {
        $id = ObjectIdValidator::toObjectId($usuarioId);
        $usuario = $id === null ? null : $this->usuarios->findById($id);
        if ($usuario === null || ($usuario['estado'] ?? null) !== true) {
            throw ApiException::unauthorized();
        }

        return self::usuarioPublico($usuario);
    }

    public function logout(string $sessionTokenHash): void
    {
        $this->sesiones->desactivar($sessionTokenHash);
    }

    /**
     * Forma pública del usuario: {id, nombre, apellido, rol, foto_perfil}.
     *
     * @param array<string, mixed> $usuario
     *
     * @return array<string, mixed>
     */
    public static function usuarioPublico(array $usuario): array
    {
        $foto = $usuario['foto_perfil'] ?? null;
        $data = Serializer::serialize([
            '_id' => $usuario['_id'] ?? null,
            'nombre' => $usuario['nombre'] ?? null,
            'apellido' => $usuario['apellido'] ?? null,
            'rol' => $usuario['rol'] ?? null,
            'foto_perfil' => is_array($foto) && is_string($foto['url'] ?? null) ? ['url' => $foto['url']] : null,
        ]);

        return is_array($data) ? $data : [];
    }

    private static function invalidCredentials(): ApiException
    {
        return new ApiException(401, 'INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.');
    }
}
