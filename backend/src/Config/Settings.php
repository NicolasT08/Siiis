<?php

declare(strict_types=1);

namespace Siiis\Config;

/**
 * Configuración de la aplicación a partir de variables de entorno (spec 10.2 y 15).
 * Falla al arrancar si falta un valor obligatorio.
 */
final readonly class Settings
{
    /** RNF-05: la sesión dura como máximo 8 horas. */
    public const MAX_JWT_TTL_SECONDS = 28800;

    public const MIN_JWT_SECRET_LENGTH = 32;

    public function __construct(
        public string $appEnv,
        public bool $appDebug,
        public string $appUrl,
        public string $frontendOrigin,
        public string $mongoUri,
        public string $mongoDbName,
        public string $mongoDbNameTest,
        public string $jwtSecret,
        public int $jwtTtlSeconds,
        public int $maxLoginAttempts,
        public int $passwordMinLength,
        public int $passwordResetTtlMinutes,
        public string $passwordResetUrl,
        public int $rateLimitLoginMax,
        public int $rateLimitLoginWindowSeconds,
        public int $rateLimitForgotMax,
        public int $rateLimitForgotWindowSeconds,
        public int $homeMaxItems,
        public string $mailHost,
        public int $mailPort,
        public string $mailUsername,
        public string $mailPassword,
        public string $mailFromAddress,
        public string $mailFromName,
        public int $perfilNombreMaxLength,
        public int $perfilProgramaMaxLength,
        public int $perfilBiografiaMaxLength,
        public int $perfilSemestreMax,
        public int $perfilTelefonoMinDigits,
        public int $perfilTelefonoMaxDigits,
        public int $perfilFotoMaxBytes,
        public string $cloudinaryCloudName,
        public string $cloudinaryApiKey,
        public string $cloudinaryApiSecret,
        public string $cloudinaryPerfilesFolder,
        public int $usersPerPageDefault,
        public int $usersPerPageMax,
        public int $documentosPerPageDefault,
        public int $documentosPerPageMax,
        public int $documentoTituloMaxLength,
        public int $documentoDescripcionMaxLength,
        public int $documentoContenidoMaxLength,
        public int $documentoIntegrantesMax,
        public string $storagePath,
    ) {
    }

    /**
     * @param array<string, mixed> $env normalmente $_ENV tras cargar el .env
     *
     * @throws ConfigException
     */
    public static function fromEnv(array $env, ?string $storagePath = null): self
    {
        $mongoUri = self::string($env, 'MONGO_URI');
        if ($mongoUri === '') {
            throw new ConfigException('Falta la variable de entorno MONGO_URI (ver backend/.env.example).');
        }

        $jwtSecret = self::string($env, 'JWT_SECRET');
        if ($jwtSecret === '') {
            throw new ConfigException('Falta la variable de entorno JWT_SECRET (ver backend/.env.example).');
        }
        if (strlen($jwtSecret) < self::MIN_JWT_SECRET_LENGTH) {
            throw new ConfigException(sprintf(
                'JWT_SECRET debe tener al menos %d caracteres.',
                self::MIN_JWT_SECRET_LENGTH,
            ));
        }

        $usersPerPageMax = self::positiveInt($env, 'USERS_PER_PAGE_MAX', 50);
        $usersPerPageDefault = self::positiveInt($env, 'USERS_PER_PAGE_DEFAULT', 20);
        if ($usersPerPageDefault > $usersPerPageMax) {
            throw new ConfigException('USERS_PER_PAGE_DEFAULT no puede ser mayor que USERS_PER_PAGE_MAX.');
        }

        $documentosPerPageMax = self::positiveInt($env, 'DOCUMENTOS_PER_PAGE_MAX', 50);
        $documentosPerPageDefault = self::positiveInt($env, 'DOCUMENTOS_PER_PAGE_DEFAULT', 12);
        if ($documentosPerPageDefault > $documentosPerPageMax) {
            throw new ConfigException('DOCUMENTOS_PER_PAGE_DEFAULT no puede ser mayor que DOCUMENTOS_PER_PAGE_MAX.');
        }

        return new self(
            appEnv: self::string($env, 'APP_ENV', 'production'),
            appDebug: self::bool($env, 'APP_DEBUG', false),
            appUrl: self::string($env, 'APP_URL', 'http://localhost:8000'),
            frontendOrigin: self::string($env, 'FRONTEND_ORIGIN', 'http://localhost:5173'),
            mongoUri: $mongoUri,
            mongoDbName: self::string($env, 'MONGO_DB_NAME', 'siiis'),
            mongoDbNameTest: self::string($env, 'MONGO_DB_NAME_TEST', 'siiis_test'),
            jwtSecret: $jwtSecret,
            jwtTtlSeconds: min(self::positiveInt($env, 'JWT_TTL_SECONDS', self::MAX_JWT_TTL_SECONDS), self::MAX_JWT_TTL_SECONDS),
            maxLoginAttempts: self::positiveInt($env, 'MAX_LOGIN_ATTEMPTS', 5),
            passwordMinLength: self::positiveInt($env, 'PASSWORD_MIN_LENGTH', 8),
            passwordResetTtlMinutes: self::positiveInt($env, 'PASSWORD_RESET_TTL_MINUTES', 30),
            passwordResetUrl: self::string($env, 'PASSWORD_RESET_URL', 'http://localhost:5173/restablecer'),
            rateLimitLoginMax: self::positiveInt($env, 'RATE_LIMIT_LOGIN_MAX', 10),
            rateLimitLoginWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_LOGIN_WINDOW_SECONDS', 900),
            rateLimitForgotMax: self::positiveInt($env, 'RATE_LIMIT_FORGOT_MAX', 5),
            rateLimitForgotWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_FORGOT_WINDOW_SECONDS', 3600),
            homeMaxItems: self::positiveInt($env, 'HOME_MAX_ITEMS', 20),
            mailHost: self::string($env, 'MAIL_HOST', 'smtp.gmail.com'),
            mailPort: self::positiveInt($env, 'MAIL_PORT', 587),
            mailUsername: self::string($env, 'MAIL_USERNAME'),
            mailPassword: self::string($env, 'MAIL_PASSWORD'),
            mailFromAddress: self::string($env, 'MAIL_FROM_ADDRESS'),
            mailFromName: self::string($env, 'MAIL_FROM_NAME', 'Semillero SIIIS'),
            // Límites de perfil (DEC-B21, PROPUESTA).
            perfilNombreMaxLength: self::positiveInt($env, 'PERFIL_NOMBRE_MAX_LENGTH', 80),
            perfilProgramaMaxLength: self::positiveInt($env, 'PERFIL_PROGRAMA_MAX_LENGTH', 120),
            perfilBiografiaMaxLength: self::positiveInt($env, 'PERFIL_BIOGRAFIA_MAX_LENGTH', 1000),
            perfilSemestreMax: self::positiveInt($env, 'PERFIL_SEMESTRE_MAX', 10),
            perfilTelefonoMinDigits: self::positiveInt($env, 'PERFIL_TELEFONO_MIN_DIGITS', 7),
            perfilTelefonoMaxDigits: self::positiveInt($env, 'PERFIL_TELEFONO_MAX_DIGITS', 20),
            perfilFotoMaxBytes: self::positiveInt($env, 'PERFIL_FOTO_MAX_BYTES', 2097152),
            // Cloudinary (spec 13.2). Vacíos: la app arranca, pero subir una foto responde 502.
            cloudinaryCloudName: self::string($env, 'CLOUDINARY_CLOUD_NAME'),
            cloudinaryApiKey: self::string($env, 'CLOUDINARY_API_KEY'),
            cloudinaryApiSecret: self::string($env, 'CLOUDINARY_API_SECRET'),
            cloudinaryPerfilesFolder: trim(self::string($env, 'CLOUDINARY_PERFILES_FOLDER', 'siiis/perfiles'), '/'),
            // Paginación de GET /users (spec 10.13.4; DEC-B26, PROPUESTA).
            usersPerPageDefault: $usersPerPageDefault,
            usersPerPageMax: $usersPerPageMax,
            // Contenido de `Documentos` (fase 3; DEC-B37, DEC-B38 y DEC-B41, PROPUESTA).
            documentosPerPageDefault: $documentosPerPageDefault,
            documentosPerPageMax: $documentosPerPageMax,
            documentoTituloMaxLength: self::positiveInt($env, 'DOCUMENTO_TITULO_MAX_LENGTH', 200),
            documentoDescripcionMaxLength: self::positiveInt($env, 'DOCUMENTO_DESCRIPCION_MAX_LENGTH', 1000),
            documentoContenidoMaxLength: self::positiveInt($env, 'DOCUMENTO_CONTENIDO_MAX_LENGTH', 50000),
            documentoIntegrantesMax: self::positiveInt($env, 'DOCUMENTO_INTEGRANTES_MAX', 20),
            storagePath: $storagePath ?? dirname(__DIR__, 2) . '/storage',
        );
    }

    public function isDevelopment(): bool
    {
        return $this->appEnv === 'development';
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function string(array $env, string $key, string $default = ''): string
    {
        $value = $env[$key] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }
        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function bool(array $env, string $key, bool $default): bool
    {
        $value = self::string($env, $key);
        if ($value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function positiveInt(array $env, string $key, int $default): int
    {
        $value = self::string($env, $key);
        if ($value === '') {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new ConfigException(sprintf('%s debe ser un entero positivo.', $key));
        }

        return (int) $value;
    }
}
