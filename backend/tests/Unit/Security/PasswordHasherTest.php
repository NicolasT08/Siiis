<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Siiis\Security\PasswordHasher;

final class PasswordHasherTest extends TestCase
{
    public function testHashEsBcryptYVerifica(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Contraseña-Segura-1');

        self::assertStringStartsWith('$2y$', $hash);
        self::assertNotSame('Contraseña-Segura-1', $hash);
        self::assertTrue($hasher->verify('Contraseña-Segura-1', $hash));
        self::assertFalse($hasher->verify('otra', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testDetectaHashQueNecesitaRehash(): void
    {
        $hasher = new PasswordHasher();
        $viejo = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertTrue($hasher->needsRehash($viejo));
    }

    public function testDummyVerifyTieneCosteBcryptReal(): void
    {
        $hasher = new PasswordHasher();
        $real = password_hash('x', PASSWORD_BCRYPT);

        $inicio = hrtime(true);
        $hasher->verify('y', $real);
        $tiempoReal = hrtime(true) - $inicio;

        $inicio = hrtime(true);
        $hasher->dummyVerify('y');
        $tiempoDummy = hrtime(true) - $inicio;

        // El hash ficticio debe ser bcrypt válido: tarda del mismo orden, no ~0.
        self::assertGreaterThan($tiempoReal / 4, $tiempoDummy);
    }
}
