<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Siiis\Config\Settings;

/**
 * Settings de prueba que no dependen de backend/.env.
 */
final class TestSettings
{
    public const JWT_SECRET = 'secreto-de-pruebas-con-mas-de-32-caracteres-0123456789';

    /**
     * @param array<string, string> $env valores que reemplazan a los por defecto
     */
    public static function make(array $env = []): Settings
    {
        $storage = sys_get_temp_dir() . '/siiis-tests-' . getmypid();

        return Settings::fromEnv($env + [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'MONGO_URI' => 'mongodb://127.0.0.1:1',
            'MONGO_DB_NAME' => 'siiis_unit',
            'JWT_SECRET' => self::JWT_SECRET,
            'FRONTEND_ORIGIN' => 'http://localhost:5173',
        ], $storage);
    }
}
