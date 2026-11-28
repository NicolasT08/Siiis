<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Siiis\Config\Settings;
use Siiis\Database\Mongo;

/**
 * Pruebas contra MongoDB Atlas en la base MONGO_DB_NAME_TEST.
 * Se saltan si no hay MONGO_URI y nunca se ejecutan contra la base principal.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?Settings $settings = null;
    protected static ?Mongo $mongo = null;
    private static ?string $skipReason = null;

    public static function setUpBeforeClass(): void
    {
        if (self::$settings !== null || self::$skipReason !== null) {
            return;
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $env = $_ENV + getenv();
        if (trim((string) ($env['MONGO_URI'] ?? '')) === '') {
            self::$skipReason = 'MONGO_URI no configurada: se omiten las pruebas de integración.';

            return;
        }

        $base = Settings::fromEnv($env + ['JWT_SECRET' => str_repeat('x', 32)]);
        $testDb = $base->mongoDbNameTest;
        if ($testDb === '' || $testDb === $base->mongoDbName || $testDb === 'siiis') {
            self::$skipReason = sprintf(
                'MONGO_DB_NAME_TEST ("%s") debe existir y ser distinta de la base principal.',
                $testDb,
            );

            return;
        }

        $storage = sys_get_temp_dir() . '/siiis-it-' . getmypid();
        self::$settings = Settings::fromEnv(['MONGO_DB_NAME' => $testDb, 'APP_ENV' => 'testing'] + $env + [
            'JWT_SECRET' => str_repeat('x', 32),
        ], $storage);
        self::$mongo = Mongo::desde(self::$settings, $testDb);

        if (!self::$mongo->ping()) {
            self::$settings = null;
            self::$mongo = null;
            self::$skipReason = 'No hay conexión con MongoDB Atlas.';
        }
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }
    }

    protected function settings(): Settings
    {
        self::assertNotNull(self::$settings);

        return self::$settings;
    }

    protected function mongo(): Mongo
    {
        self::assertNotNull(self::$mongo);
        self::assertNotSame('siiis', self::$mongo->databaseName());

        return self::$mongo;
    }

    protected static function correoUnico(): string
    {
        return 'it-' . bin2hex(random_bytes(6)) . '@example.test';
    }
}
