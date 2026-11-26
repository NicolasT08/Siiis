<?php

declare(strict_types=1);

namespace Siiis\Config;

use Siiis\Support\ClientIp;

/**
 * Configuración de la aplicación a partir de variables de entorno (spec 10.2 y 15).
 * Falla al arrancar si falta un valor obligatorio.
 */
final readonly class Settings
{
    /** RNF-05: la sesión dura como máximo 8 horas. */
    public const MAX_JWT_TTL_SECONDS = 28800;

    public const MIN_JWT_SECRET_LENGTH = 32;

    /** Valores de `quality` de Cloudinary que se admiten al subir (DEC-B42). */
    private const PATRON_CALIDAD = '/^(auto(:(best|good|eco|low))?|[1-9][0-9]?|100)$/';

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
        public int $rateLimitChangePasswordMax,
        public int $rateLimitChangePasswordWindowSeconds,
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
        public int $documentoAdjuntoMaxBytes,
        public int $documentoAdjuntosMax,
        public string $cloudinaryDocumentosFolder,
        public int $documentoPdfMaxBytes,
        public int $perfilFotoLadoPx,
        public int $documentoImagenMaxAnchoPx,
        public string $cloudinaryCalidad,
        public int $rateLimitReviewsMax,
        public int $rateLimitReviewsWindowSeconds,
        public int $rateLimitContactMax,
        public int $rateLimitContactWindowSeconds,
        public int $resenasPerPageDefault,
        public int $resenasPerPageMax,
        public int $resenaNombreMaxLength,
        public int $resenaComentarioMaxLength,
        public string $contactoCorreoDestino,
        public int $contactoNombreMaxLength,
        public int $contactoAsuntoMaxLength,
        public int $contactoMensajeMaxLength,
        public int $mensajesPerPageDefault,
        public int $mensajesPerPageMax,
        public int $rateLimitInscriptionsMax,
        public int $rateLimitInscriptionsWindowSeconds,
        public int $inscripcionesPerPageDefault,
        public int $inscripcionesPerPageMax,
        public int $inscripcionDocumentosMax,
        public string $cloudinaryInscripcionesFolder,
        public int $inscripcionEnlaceTtlSeconds,
        /** @var list<string> dominios admitidos en el correo del aspirante; vacío = cualquiera (DEC-B51) */
        public array $inscripcionDominiosCorreo,
        public int $inscripcionNombreMaxLength,
        public int $inscripcionCartaMinLength,
        public int $inscripcionCartaMaxLength,
        public string $inscripcionCorreoAviso,
        /** @var list<string> IP o rangos CIDR de proxies de confianza; vacío = solo REMOTE_ADDR (fase 5) */
        public array $trustedProxies,
        /** Origen permitido por CORS fuera de desarrollo (plan B: API en otro dominio); vacío = sin CORS */
        public string $corsOrigin,
        /** Client ID de Google Identity Services (RF-17); vacío = POST /auth/google no existe (404) */
        public string $googleClientId,
        /** Dominio que exige el claim `hd` del id_token de Google (DEC-B61) */
        public string $googleDominioPermitido,
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

        [$resenasPerPageDefault, $resenasPerPageMax] = self::paginacion($env, 'RESENAS', 10, 50);
        [$mensajesPerPageDefault, $mensajesPerPageMax] = self::paginacion($env, 'MENSAJES', 20, 50);
        [$inscripcionesPerPageDefault, $inscripcionesPerPageMax] = self::paginacion($env, 'INSCRIPCIONES', 20, 50);

        $cartaMin = self::positiveInt($env, 'INSCRIPCION_CARTA_MIN_LENGTH', 100);
        $cartaMax = self::positiveInt($env, 'INSCRIPCION_CARTA_MAX_LENGTH', 3000);
        if ($cartaMin > $cartaMax) {
            throw new ConfigException('INSCRIPCION_CARTA_MIN_LENGTH no puede ser mayor que INSCRIPCION_CARTA_MAX_LENGTH.');
        }

        $cloudinaryCalidad = self::string($env, 'CLOUDINARY_CALIDAD', 'auto');
        if (preg_match(self::PATRON_CALIDAD, $cloudinaryCalidad) !== 1) {
            throw new ConfigException(
                'CLOUDINARY_CALIDAD debe ser auto, auto:best, auto:good, auto:eco, auto:low o un número de 1 a 100.',
            );
        }

        $appEnv = self::string($env, 'APP_ENV', 'production');
        $appDebug = self::bool($env, 'APP_DEBUG', false);
        if ($appEnv === 'production' && $appDebug) {
            // Con APP_DEBUG=true los errores 500 incluyen trazas y rutas del servidor.
            throw new ConfigException('APP_DEBUG=true no está permitido con APP_ENV=production.');
        }

        return new self(
            appEnv: $appEnv,
            appDebug: $appDebug,
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
            rateLimitChangePasswordMax: self::positiveInt($env, 'RATE_LIMIT_CHANGE_PASSWORD_MAX', 10),
            rateLimitChangePasswordWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_CHANGE_PASSWORD_WINDOW_SECONDS', 900),
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
            // Adjuntos de documentos (DEC-B39, DEC-B40 y DEC-B42, PROPUESTA): imágenes de hasta 5 MB, PDF de
            // hasta 3 MB y 10 por documento.
            documentoAdjuntoMaxBytes: self::positiveInt($env, 'DOCUMENTO_ADJUNTO_MAX_BYTES', 5242880),
            documentoAdjuntosMax: self::positiveInt($env, 'DOCUMENTO_ADJUNTOS_MAX', 10),
            cloudinaryDocumentosFolder: trim(self::string($env, 'CLOUDINARY_DOCUMENTOS_FOLDER', 'siiis/documentos'), '/'),
            documentoPdfMaxBytes: self::positiveInt($env, 'DOCUMENTO_PDF_MAX_BYTES', 3145728),
            // Optimización de espacio en Cloudinary al subir (DEC-B42, PROPUESTA).
            perfilFotoLadoPx: self::positiveInt($env, 'PERFIL_FOTO_LADO_PX', 400),
            documentoImagenMaxAnchoPx: self::positiveInt($env, 'DOCUMENTO_IMAGEN_MAX_ANCHO_PX', 1600),
            cloudinaryCalidad: $cloudinaryCalidad,
            // Fase 4: reseñas y contacto (DEC-B43 a DEC-B48, PROPUESTA). Rate limiting por IP y por hora.
            rateLimitReviewsMax: self::positiveInt($env, 'RATE_LIMIT_REVIEWS_MAX', 10),
            rateLimitReviewsWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_REVIEWS_WINDOW_SECONDS', 3600),
            rateLimitContactMax: self::positiveInt($env, 'RATE_LIMIT_CONTACT_MAX', 20),
            rateLimitContactWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_CONTACT_WINDOW_SECONDS', 3600),
            resenasPerPageDefault: $resenasPerPageDefault,
            resenasPerPageMax: $resenasPerPageMax,
            resenaNombreMaxLength: self::positiveInt($env, 'RESENA_NOMBRE_MAX_LENGTH', 80),
            resenaComentarioMaxLength: self::positiveInt($env, 'RESENA_COMENTARIO_MAX_LENGTH', 1000),
            contactoCorreoDestino: self::string($env, 'CONTACTO_CORREO_DESTINO'),
            contactoNombreMaxLength: self::positiveInt($env, 'CONTACTO_NOMBRE_MAX_LENGTH', 80),
            contactoAsuntoMaxLength: self::positiveInt($env, 'CONTACTO_ASUNTO_MAX_LENGTH', 150),
            contactoMensajeMaxLength: self::positiveInt($env, 'CONTACTO_MENSAJE_MAX_LENGTH', 3000),
            mensajesPerPageDefault: $mensajesPerPageDefault,
            mensajesPerPageMax: $mensajesPerPageMax,
            // Inscripciones (DEC-B48 a DEC-B52, PROPUESTA).
            rateLimitInscriptionsMax: self::positiveInt($env, 'RATE_LIMIT_INSCRIPTIONS_MAX', 20),
            rateLimitInscriptionsWindowSeconds: self::positiveInt($env, 'RATE_LIMIT_INSCRIPTIONS_WINDOW_SECONDS', 3600),
            inscripcionesPerPageDefault: $inscripcionesPerPageDefault,
            inscripcionesPerPageMax: $inscripcionesPerPageMax,
            inscripcionDocumentosMax: self::positiveInt($env, 'INSCRIPCION_DOCUMENTOS_MAX', 3),
            cloudinaryInscripcionesFolder: trim(
                self::string($env, 'CLOUDINARY_INSCRIPCIONES_FOLDER', 'siiis/inscripciones'),
                '/',
            ),
            inscripcionEnlaceTtlSeconds: self::positiveInt($env, 'INSCRIPCION_ENLACE_TTL_SECONDS', 3600),
            inscripcionDominiosCorreo: self::dominios($env, 'INSCRIPCION_DOMINIOS_CORREO', ['uptc.edu.co']),
            inscripcionNombreMaxLength: self::positiveInt($env, 'INSCRIPCION_NOMBRE_MAX_LENGTH', 120),
            inscripcionCartaMinLength: $cartaMin,
            inscripcionCartaMaxLength: $cartaMax,
            inscripcionCorreoAviso: self::string($env, 'INSCRIPCION_CORREO_AVISO'),
            trustedProxies: self::proxies($env, 'TRUSTED_PROXIES'),
            corsOrigin: self::origen($env, 'CORS_ORIGIN'),
            googleClientId: self::string($env, 'GOOGLE_CLIENT_ID'),
            googleDominioPermitido: self::dominioGoogle($env),
            storagePath: $storagePath ?? dirname(__DIR__, 2) . '/storage',
        );
    }

    public function isDevelopment(): bool
    {
        return $this->appEnv === 'development';
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    /**
     * Origen al que se le permite CORS: FRONTEND_ORIGIN en desarrollo; fuera de desarrollo, solo CORS_ORIGIN
     * (vacío = sin CORS, el caso normal en Hostinger, donde frontend y API comparten dominio).
     */
    public function origenCors(): string
    {
        return $this->isDevelopment() ? $this->frontendOrigin : $this->corsOrigin;
    }

    /**
     * La ventana de rate limiting más larga: un contador sin cambios desde hace más que esto ya venció y se
     * puede borrar (`cron/limpiar_ratelimit.php`).
     */
    public function ventanaRateLimitMaxima(): int
    {
        return max(
            $this->rateLimitLoginWindowSeconds,
            $this->rateLimitForgotWindowSeconds,
            $this->rateLimitChangePasswordWindowSeconds,
            $this->rateLimitReviewsWindowSeconds,
            $this->rateLimitContactWindowSeconds,
            $this->rateLimitInscriptionsWindowSeconds,
        );
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
     * GOOGLE_DOMINIO_PERMITIDO: un solo dominio (por defecto uptc.edu.co). El claim `hd` debe ser exactamente este.
     *
     * @param array<string, mixed> $env
     */
    private static function dominioGoogle(array $env): string
    {
        $dominios = self::dominios($env, 'GOOGLE_DOMINIO_PERMITIDO', ['uptc.edu.co']);
        if (count($dominios) !== 1) {
            throw new ConfigException('GOOGLE_DOMINIO_PERMITIDO debe ser un solo dominio (p. ej. uptc.edu.co).');
        }

        return $dominios[0];
    }

    /**
     * Lista de dominios separados por comas, en minúsculas y sin "@". Si la variable no existe se usa
     * `$default`; si existe pero está vacía, la lista queda vacía (cualquier dominio).
     *
     * @param array<string, mixed> $env
     * @param list<string>         $default
     *
     * @return list<string>
     */
    private static function dominios(array $env, string $key, array $default): array
    {
        if (!array_key_exists($key, $env) || !is_scalar($env[$key])) {
            return $default;
        }

        $dominios = [];
        foreach (explode(',', (string) $env[$key]) as $dominio) {
            $dominio = ltrim(mb_strtolower(trim($dominio)), '@');
            if ($dominio === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $dominio) !== 1) {
                throw new ConfigException(sprintf('%s tiene un dominio no válido: "%s".', $key, $dominio));
            }
            $dominios[] = $dominio;
        }

        return array_values(array_unique($dominios));
    }

    /**
     * Un origen exacto `https://dominio[:puerto]`, sin ruta ni comodines. Vacío si la variable no existe.
     *
     * @param array<string, mixed> $env
     */
    private static function origen(array $env, string $key): string
    {
        $origen = self::string($env, $key);
        if ($origen === '') {
            return '';
        }
        if (preg_match('#^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?\z#i', $origen) !== 1) {
            throw new ConfigException(sprintf(
                '%s debe ser un origen exacto con https (p. ej. https://siiis.com.co), sin ruta ni "*": "%s".',
                $key,
                $origen,
            ));
        }

        return strtolower($origen);
    }

    /**
     * Lista de IP o rangos CIDR (IPv4 o IPv6) separados por comas. Vacía si la variable no existe o está vacía.
     *
     * @param array<string, mixed> $env
     *
     * @return list<string>
     */
    private static function proxies(array $env, string $key): array
    {
        $proxies = [];
        foreach (explode(',', self::string($env, $key)) as $proxy) {
            $proxy = trim($proxy);
            if ($proxy === '') {
                continue;
            }
            if (!ClientIp::esRangoValido($proxy)) {
                throw new ConfigException(sprintf('%s tiene una IP o rango no válido: "%s".', $key, $proxy));
            }
            $proxies[] = $proxy;
        }

        return array_values(array_unique($proxies));
    }

    /**
     * `{PREFIJO}_PER_PAGE_DEFAULT` y `{PREFIJO}_PER_PAGE_MAX`; el valor por defecto no puede pasar del máximo.
     *
     * @param array<string, mixed> $env
     *
     * @return array{int, int}
     */
    private static function paginacion(array $env, string $prefijo, int $default, int $max): array
    {
        $max = self::positiveInt($env, $prefijo . '_PER_PAGE_MAX', $max);
        $default = self::positiveInt($env, $prefijo . '_PER_PAGE_DEFAULT', $default);
        if ($default > $max) {
            throw new ConfigException(sprintf(
                '%1$s_PER_PAGE_DEFAULT no puede ser mayor que %1$s_PER_PAGE_MAX.',
                $prefijo,
            ));
        }

        return [$default, $max];
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
